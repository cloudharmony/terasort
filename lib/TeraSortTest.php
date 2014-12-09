<?php
// Copyright 2014 CloudHarmony Inc.
// 
// Licensed under the Apache License, Version 2.0 (the "License");
// you may not use this file except in compliance with the License.
// You may obtain a copy of the License at
// 
//     http://www.apache.org/licenses/LICENSE-2.0
// 
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.


/**
 * Used to manage GEEKBENCH testing
 */
require_once(dirname(__FILE__) . '/util.php');
ini_set('memory_limit', '16m');
date_default_timezone_set('UTC');

class TeraSortTest {
  
  /**
   * name of the file where serializes options should be written to for given 
   * test iteration
   */
  const TERASORT_TEST_OPTIONS_FILE_NAME = '.options';
  
  /**
   * optional results directory object was instantiated for
   */
  private $dir;
  
  /**
   * run options
   */
  private $options;
  
  
  /**
   * constructor
   * @param string $dir optional results directory object is being instantiated
   * for. If set, runtime parameters will be pulled from the .options file. Do
   * not set when running a test
   */
  public function TeraSortTest($dir=NULL) {
    $this->dir = $dir;
  }
  
  /**
   * checks for dynamic tokens and expressions in $variable and replaces and 
   * executes accordingly. Return value is the final value
   * @param string $variable the variable to evaluate
   * @return string
   */
  private function checkForExpression($variable) {
    // threads is based on number of CPUs
    if (preg_match('/^(.*)=(.*)$/', $variable, $m)) {
      $key = $m[1];
      $value = $m[2];
      if (preg_match('/{cpus}/', $value) || preg_match('/{nodes}/', $value)) {
        $cpus = trim(shell_exec('nproc'))*1;
        $value = str_replace(' ', '', str_replace('{cpus}', $cpus, $value));
        $value = str_replace(' ', '', str_replace('{nodes}', $this->options['meta_hdfs_nodes'], $value));
        // expression
        if (preg_match('/[\*\+\-\/]/', $value)) {
          eval(sprintf('$value=%s;', $value));
        }
        $value *= 1;
      }
      $variable = sprintf('%s=%s', $key, $value);
    }
    return $variable;
  }
  
  /**
   * writes test results and finalizes testing
   * @return boolean
   */
  private function endTest() {
    $ended = FALSE;
    $dir = $this->options['output'];
    
    // add test stop time
    $this->options['test_stopped'] = date('Y-m-d H:i:s');
    
    // get hadoop version
    $pieces = explode("\n", trim(shell_exec('hadoop version 2>&1')));
    if ($pieces[0]) {
      $pieces = explode(' ', $pieces[0]);
      $this->options['hadoop_version'] = $pieces[count($pieces) - 1];
      print_msg(sprintf('Set hadoop_version=%s', $this->options['hadoop_version']), isset($this->options['verbose']), __FILE__, __LINE__);
    }
    
    // get java version
    $jversion = NULL;
    $jvendor = NULL;
    foreach(explode("\n", shell_exec('java -version 2>&1')) as $line) {
      if (preg_match('/"([0-9\._]+)"/', trim($line), $m)) $jversion = $m[1];
      else if (!$jvendor && preg_match('/openjdk/i', trim($line))) $jvendor = 'OpenJDK';
      else if (!$jvendor && preg_match('/hotspot/i', trim($line))) $jvendor = 'Oracle';
      else if (!$jvendor && preg_match('/ibm/i', trim($line))) $jvendor = 'IBM';
    }
    if ($jversion) {
      $this->options['java_version'] = sprintf('%s%s', $jvendor ? $jvendor . ' ' : '', $jversion);
      print_msg(sprintf('Set java_version=%s', $this->options['java_version']), isset($this->options['verbose']), __FILE__, __LINE__);
    }
    
    foreach(array('teragen', 'terasort', 'teravalidate') as $prog) {
      $ofile = sprintf('%s/%s.out', $this->options['output'], $prog);
      if (file_exists($ofile)) {
        foreach(file($ofile) as $line) {
          if (preg_match('/map tasks\s*=\s*([0-9]+)$/', trim($line), $m)) $this->options[sprintf('%s_map_tasks', $prog)] = $m[1]*1;
          else if (preg_match('/reduce tasks\s*=\s*([0-9]+)$/', trim($line), $m)) $this->options[sprintf('%s_reduce_tasks', $prog)] = $m[1]*1;
        }
      }
    }
    
    // serialize options
    $ofile = sprintf('%s/%s', $dir, self::TERASORT_TEST_OPTIONS_FILE_NAME);
    if (is_dir($dir) && is_writable($dir)) {
      $fp = fopen($ofile, 'w');
      fwrite($fp, serialize($this->options));
      fclose($fp);
      $ended = TRUE;
    }
    if (file_exists(sprintf('%s/teragen.xml', $this->options['output']))) {
      exec(sprintf('cd %s;zip terasort-conf.zip *.xml;rm -f *.xml', $this->options['output']));
      print_msg(sprintf('Created configuration test artifact %s/terasort-conf.zip', $this->options['output']), isset($this->options['verbose']), __FILE__, __LINE__);
    }
    if (file_exists(sprintf('%s/teragen.out', $this->options['output']))) {
      exec(sprintf('cd %s;zip terasort-logs.zip *.out *.log;mv output.log output.tmp;rm -f *.out *.log;mv output.tmp output.log', $this->options['output']));
      print_msg(sprintf('Created log test artifact %s/terasort-logs.zip', $this->options['output']), isset($this->options['verbose']), __FILE__, __LINE__);
    }
    
    return $ended;
  }
  
  /**
   * returns results from testing as a hash of key/value pairs. If results are
   * not available, returns NULL
   * @param boolean $verbose show verbose output
   * @return array
   */
  public function getResults($verbose=NULL) {
    $results = NULL;
    if (is_dir($this->dir) && self::getSerializedOptions($this->dir) && $this->getRunOptions()) {
      $results = array();
      foreach($this->options as $key => $val) {
        $col = $key;
        $results[$col] = is_array($val) ? implode(',', $val) : $val;
      }
    }
    return $results;
  }
  
  /**
   * returns run options represents as a hash
   * @return array
   */
  public function getRunOptions() {
    if (!isset($this->options)) {
      if ($this->dir) $this->options = self::getSerializedOptions($this->dir);
      else {
        // default run argument values
        $sysInfo = get_sys_info();
        $defaults = array(
          'meta_compute_service' => 'Not Specified',
          'meta_cpu' => $sysInfo['cpu'],
          'meta_instance_id' => 'Not Specified',
          'meta_map_reduce_version' => 1,
          'meta_memory' => $sysInfo['memory_gb'] > 0 ? $sysInfo['memory_gb'] . ' GB' : $sysInfo['memory_mb'] . ' MB',
          'meta_os' => $sysInfo['os_info'],
          'meta_provider' => 'Not Specified',
          'meta_storage_config' => 'Not Specified',
          'output' => trim(shell_exec('pwd')),
          'teragen_dir' => 'terasort-input',
          'teragen_rows' => 10000000000,
          'terasort_dir' => 'terasort-output',
          'teravalidate_dir' => 'terasort-validate'
        );
        $opts = array(
          'hadoop_examples_jar:',
          'hadoop_heapsize:',
          'meta_compute_service:',
          'meta_compute_service_id:',
          'meta_cpu:',
          'meta_hdfs_nodes:',
          'meta_instance_id:',
          'meta_map_reduce_version:',
          'meta_memory:',
          'meta_os:',
          'meta_provider:',
          'meta_provider_id:',
          'meta_region:',
          'meta_resource_id:',
          'meta_run_id:',
          'meta_storage_config:',
          'meta_storage_volumes:',
          'meta_storage_volume_size:',
          'meta_test_id:',
          'no_purge',
          'output:',
          'teragen_args:',
          'teragen_balance',
          'teragen_dir:',
          'teragen_rows:',
          'terasort_args:',
          'terasort_dir:',
          'teravalidate_args:',
          'teravalidate_dir:',
          'v' => 'verbose'
        );
        $this->options = parse_args($opts, array('teragen_args', 'terasort_args', 'teravalidate_args')); 
        foreach($defaults as $key => $val) {
          if (!isset($this->options[$key])) $this->options[$key] = $val;
        }
      }
      
      // determine number of volumes and sizes from /hdfsN mounts
      if (!isset($this->options['meta_storage_volumes']) && !isset($this->options['meta_storage_volume_size'])) {
        $volumes = 0;
        $sizes = array();
        $counter = 1;
        while(is_dir($dir = sprintf('/hdfs%d', $counter++))) {
          $volumes++;
          $sizes[] = round(get_free_space($dir)/1024);
        }
        if ($volumes && array_sum($sizes)) {
          $size = round(array_sum($sizes)/count($sizes));
          print_msg(sprintf('Setting meta_storage_volumes=%d and meta_storage_volume_size=%d GB from directories /hdfsN', $volumes, $size), isset($this->options['verbose']), __FILE__, __LINE__);
          $this->options['meta_storage_volumes'] = $volumes;
          $this->options['meta_storage_volume_size'] = $size;
        }
      }
      
      // determine meta_hdfs_nodes using hdfs dfsadmin -report
      if (!isset($this->options['meta_hdfs_nodes']) || !is_numeric($this->options['meta_hdfs_nodes']) || !$this->options['meta_hdfs_nodes']) {
        $nodes = NULL;
        foreach(explode("\n", shell_exec('sudo -u hdfs hdfs dfsadmin -report 2>/dev/null')) as $line) {
          if (preg_match('/Live datanodes \(([0-9]+)\)/', trim($line), $m)) {
            $nodes = $m[1]*1;
            print_msg(sprintf('Successfully obtained meta_hdfs_nodes=%d using hdfs dfsadmin -report', $nodes), isset($this->options['verbose']), __FILE__, __LINE__);
            break;
          }
        }
        if (!$nodes) {
          print_msg(sprintf('Unable to obtain meta_hdfs_nodes using hdfs dfsadmin -report - user may not have sudo -u hdfs permission'), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
          $nodes = 1;
        }
        $this->options['meta_hdfs_nodes'] = $nodes;
      }
      
      // extrapolate args
      foreach(array('teragen_args', 'terasort_args', 'teravalidate_args') as $key) {
        if (isset($this->options[$key]) && (!is_array($this->options[$key]) || !$this->options[$key])) unset($this->options[$key]);
        else if (isset($this->options[$key])) {
          foreach($this->options[$key] as $i => $variable) {
            $this->options[$key][$i] = $this->checkForExpression($variable);
            print_msg(sprintf('Set %s runtime argument to %s [base=%s]', $key, $this->options[$key][$i], $variable), isset($this->options['verbose']), __FILE__, __LINE__);
          }
        }
      }
    }
    return $this->options;
  }
  
  /**
   * returns options from the serialized file where they are written when a 
   * test completes
   * @param string $dir the directory where results were written to
   * @return array
   */
  public static function getSerializedOptions($dir) {
    return unserialize(file_get_contents(sprintf('%s/%s', $dir, self::TERASORT_TEST_OPTIONS_FILE_NAME)));
  }
  
  /**
   * initiates TeraSort testing. returns TRUE on success, FALSE otherwise
   * @return boolean
   */
  public function test() {
    $this->getRunOptions();
    $this->options['test_started'] = date('Y-m-d H:i:s');
    
    $cmds = array();
    $cmds['teragen'] = sprintf('hadoop jar%s teragen%s %d %s', 
                       isset($this->options['hadoop_examples_jar']) ? ' ' . $this->options['hadoop_examples_jar'] : '',
                       isset($this->options['teragen_args']) ? ' -D' . implode(' -D', $this->options['teragen_args']) : '',
                       $this->options['teragen_rows'], $this->options['teragen_dir']);
    $cmds['terasort'] = sprintf('hadoop jar%s terasort%s %s %s',
                       isset($this->options['hadoop_examples_jar']) ? ' ' . $this->options['hadoop_examples_jar'] : '',
                       isset($this->options['terasort_args']) ? ' -D' . implode(' -D', $this->options['terasort_args']) : '',
                       $this->options['teragen_dir'], $this->options['terasort_dir']);
    $cmds['teravalidate'] = sprintf('hadoop jar%s teravalidate%s %s %s',
                       isset($this->options['hadoop_examples_jar']) ? ' ' . $this->options['hadoop_examples_jar'] : '',
                       isset($this->options['teravalidate_args']) ? ' -D' . implode(' -D', $this->options['teravalidate_args']) : '',
                       $this->options['terasort_dir'], $this->options['teravalidate_dir']);
    $purge = array();
    
    foreach($cmds as $prog => $cmd) {
      $dir = $this->options[sprintf('%s_dir', $prog)];
      // purge existing directory if necessary
      exec(sprintf('hadoop fs -rm -r %s >/dev/null 2>&1', $dir));
      $this->options[sprintf('%s_cmd', $prog)] = $cmd;
      $success = FALSE;
      print_msg($cmd, isset($this->options['verbose']), __FILE__, __LINE__);
      $ofile = sprintf('%s/%s.out', $this->options['output'], $prog);
      $xfile = sprintf('%s/%s.status', $this->options['output'], $prog);
      if (isset($this->options['hadoop_heapsize'])) {
        print_msg(sprintf('Setting heapsize to %s for %s', $this->options['hadoop_heapsize'], $prog), isset($this->options['verbose']), __FILE__, __LINE__);
        $cmd = sprintf('export HADOOP_HEAPSIZE=%s;%s', $this->options['hadoop_heapsize'], $cmd);
      }
      $cmd = sprintf('%s >%s 2>&1 | echo $? >%s', $cmd, $ofile, $xfile);
      $start = time();
      passthru($cmd);
      $duration = time() - $start;
      $ecode = trim(file_get_contents($xfile));
      $ecode = strlen($ecode) && is_numeric($ecode) ? $ecode*1 : NULL;
      unlink($xfile);
      if (file_exists($ofile) && !filesize($ofile)) unlink($ofile);
      if ($ecode !== 0) {
        print_msg(sprintf('Unable run %s - exit code %d', $prog, $ecode), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
      }
      else {
        // check if _SUCCESS file exists
        $ecode = exec(sprintf('hadoop fs -ls %s | grep SUCCESS >/dev/null 2>&1;echo $?', $dir));
        $ecode = strlen($ecode) && is_numeric($ecode) ? $ecode*1 : NULL;
        if ($ecode === 0) {
          $success = TRUE;
          print_msg(sprintf('%s finished successfully', $prog), isset($this->options['verbose']), __FILE__, __LINE__); 
        }
        else print_msg(sprintf('Unable run %s - %s/_SUCCESS does not exist in hdfs', $prog, $dir), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
      }
      
      if (!$success) {
        $this->options[sprintf('%s_failed', $prog)] = TRUE;
        break;
      }
      else {
        $this->options[sprintf('%s_time', $prog)] = $duration;
        $purge[$prog] = $dir;
        foreach(explode("\n", trim(shell_exec(sprintf('hadoop fs -ls %s/_logs/history 2>/dev/null', $dir)))) as $line) {
          if (preg_match('/Found/', trim($line))) continue;
          $pieces = explode(' ', trim($line));
          if ($file = $pieces[count($pieces) - 1]) {
            $ofile = sprintf('%s/%s.%s', $this->options['output'], $prog, preg_match('/xml$/', $file) ? 'xml' : 'log');
            exec(sprintf('hadoop fs -cat %s >%s 2>/dev/null', $file, $ofile));
            if (file_exists($ofile) && filesize($ofile)) print_msg(sprintf('Successfully exported output file %s', basename($ofile)), isset($this->options['verbose']), __FILE__, __LINE__);
            else {
              print_msg(sprintf('Unable to export output file %s', basename($ofile)), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
              exec(sprintf('rm -f %s', $ofile));
            }
          }
        }
        if ($prog == 'teragen' && isset($this->options['teragen_balance'])) {
          print_msg(sprintf('attempting to rebalance hdfs cluster because --teragen_balance was set'), isset($this->options['verbose']), __FILE__, __LINE__);
          passthru(sprintf('sudo -u hdfs hdfs balancer -threshold 5 %s2>&1', isset($this->options['verbose']) ? '' : '>/dev/null '));
        }
      }
    }
    
    // determine blocksize and replication factors
    foreach($purge as $prog => $dir) {
      foreach(explode("\n", shell_exec(sprintf('hdfs dfs -ls -R %s', $dir))) as $line) {
        if (preg_match('/^\-rw/', trim($line)) && preg_match('/part\-/', trim($line))) {
          $pieces = explode(' ', trim($line));
          $file = $pieces[count($pieces) - 1];
          print_msg(sprintf('Attempting to get %s block size and replication factor using file %s', $prog, $file), isset($this->options['verbose']), __FILE__, __LINE__);
          $pieces = explode(' ', trim(shell_exec(sprintf('hdfs dfs -stat "%s" %s', '%o %r', $file))));
          if (count($pieces) == 2 && is_numeric($pieces[0]) && is_numeric($pieces[1])) {
            $blocksize = round(($pieces[0]/1024)/1024);
            $replication = $pieces[1]*1;
            $this->options[sprintf('%s_blocksize', $prog)] = $blocksize;
            $this->options[sprintf('%s_replication', $prog)] = $replication;
            print_msg(sprintf('Successfully obtained %s blocksize [%d MB] and replication factor [%d]', $prog, $blocksize, $replication), isset($this->options['verbose']), __FILE__, __LINE__);
          }
          else print_msg(sprintf('Unable to obtain blocksize and replication factor %s. Output: %s', $prog, implode(' ', $pieces)), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
          break;
        }
      }
    }
    
    // purge hdfs directories
    if ($purge && !isset($this->options['no_purge'])) {
      foreach($purge as $prog => $dir) {
        print_msg(sprintf('Purging hdfs directory %s for %s', $dir, $prog), isset($this->options['verbose']), __FILE__, __LINE__);
        passthru(sprintf('hadoop fs -rm -r %s', $dir));
      }
    }
    
    $this->endTest();
    
    return $success;
  }
  
  /**
   * validates test dependencies. returns an array containing the missing 
   * dependencies (array is empty if all dependencies are valid)
   * @return array
   */
  public static function validateDependencies() {
    $dependencies = array('hadoop' => 'hadoop', 'hdfs' => 'hdfs', 'java' => 'java', 'zip' => 'zip');
    return validate_dependencies($dependencies);
  }
  
  /**
   * validate run options. returns an array populated with error messages 
   * indexed by the argument name. If options are valid, the array returned
   * will be empty
   * @return array
   */
  public function validateRunOptions() {
    $this->getRunOptions();
    $validate = array(
      'hadoop_heapsize' => array('min' => 128),
      'meta_hdfs_nodes' => array('min' => 1, 'required' => TRUE),
      'meta_map_reduce_version' => array('min' => 1, 'max' => 2, 'required' => TRUE),
      'meta_storage_volumes' => array('min' => 1),
      'meta_storage_volume_size' => array('min' => 1),
      'output' => array('write' => TRUE),
      'teragen_rows' => array('min' => 1000000, 'required' => TRUE)
    );
    $validated = validate_options($this->options, $validate);
    if (!is_array($validated)) $validated = array();
    
    if (isset($this->options['hadoop_examples_jar']) && !file_exists($this->options['hadoop_examples_jar'])) {
      $validated['hadoop_examples_jar'] = sprintf('%s file', $this->options['hadoop_examples_jar']);
    }
    foreach(array('teragen_args', 'terasort_args', 'teravalidate_args') as $arg) {
      if (isset($this->options[$arg]) && is_array($this->options[$arg])) {
        foreach($this->options[$arg] as $a) {
          if (!preg_match('/^[a-zA-Z0-9\.]+=[a-zA-Z0-9\.]+$/', $a)) {
            $validated[$arg] = sprintf('%s argument value %s is not formats as [arg]=[val]', $arg, $a);
          }
        }
      }
    }
      
    return $validated;
  }
}
?>

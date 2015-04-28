#!/bin/bash
# Copyright 2014 CloudHarmony Inc.
# 
# Licensed under the Apache License, Version 2.0 (the "License");
# you may not use this file except in compliance with the License.
# You may obtain a copy of the License at
# 
#     http://www.apache.org/licenses/LICENSE-2.0
# 
# Unless required by applicable law or agreed to in writing, software
# distributed under the License is distributed on an "AS IS" BASIS,
# WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
# See the License for the specific language governing permissions and
# limitations under the License.


if [ "$1" == "-h" ] || [ "$1" == "--help" ] ; then
  cat << EOF
Usage: run.sh [options]

This repository provides an execution wrapper for the Hadoop TeraSort 
benchmark. TeraSort is a widely used benchmark for Hadoop distributions. As 
the name implies, it is a sorting benchmark. The TeraSort package includes 
3 map/reduce applications: TeraGen (data generator), TeraSort (map/reduce 
sorting), and TeraValidate (sort validation). For more information see:

https://hadoop.apache.org/docs/current/api/org/apache/hadoop/examples/terasort/package-summary.html


RUNTIME PARAMETERS
Use of run.sh requires the Hadoop cluster to be provisioned and accessible to 
the user via the 'hadoop' command. The following runtime parameters and 
environment metadata may be specified (using run.sh arguments):

--collectd_rrd              If set, collectd rrd stats will be captured from 
                            --collectd_rrd_dir. To do so, when testing starts,
                            existing directories in --collectd_rrd_dir will 
                            be renamed to .bak, and upon test completion 
                            any directories not ending in .bak will be zipped
                            and saved along with other test artifacts (as 
                            collectd-rrd.zip). User MUST have sudo privileges
                            to use this option
                            
--collectd_rrd_dir          Location where collectd rrd files are stored - 
                            default is /var/lib/collectd/rrd

--hadoop_conf_dir           Hadoop conf directory (see 'settings_*' output 
                            parameters below). Default is [hadoop_home]/etc/hadoop

--hadoop_examples_jar       Path to JAR file containing TeraSort programs. 
                            Default is [hadoop_home]/share/hadoop/mapreduce/hadoop-mapreduce-examples-2.6.0.jar
                            
--hadoop_heapsize           Optional explicit heap size in megabytes for the 
                            hadoop daemon. Value is set to the HADOOP_HEAPSIZE 
                            environment variable
                            
--hadoop_home               The base directory where hadoop is installed. Default 
                            is /usr/local/hadoop. If [hadoop_home]/bin is not 
                            already included in PATH, it will be added

--meta_compute_service      The name of the compute service this test pertains
                            to. May also be specified using the environment 
                            variable bm_compute_service
                            
--meta_compute_service_id   The id of the compute service this test pertains
                            to. Added to saved results. May also be specified 
                            using the environment variable bm_compute_service_id
                            
--meta_cpu                  CPU descriptor - if not specified, it will be set 
                            using the 'model name' attribute in /proc/cpuinfo
                            
--meta_instance_id          The compute service instance type this test pertains 
                            to (e.g. c3.xlarge). May also be specified using 
                            the environment variable bm_instance_id
                            
--meta_map_reduce_version   The map reduce version in place on the Hadoop 
                            cluster. Default is 2. Options are 1 or 2
                            
--meta_memory               Memory descriptor - if not specified, the system
                            memory size will be used
                            
--meta_hdfs_nodes           Number of data nodes in the HDFS cluster. If not 
                            specified, an attempt to determine this value will 
                            be tried using 'hdfs dfsadmin -report'. However, 
                            only the 'hdfs' user has access to this information 
                            using that command and if not accessible, the value 
                            of this parameter defaults to 1
                            
--meta_os                   Operating system descriptor - if not specified, 
                            it will be taken from the first line of /etc/issue
                            
--meta_provider             The name of the cloud provider this test pertains
                            to. May also be specified using the environment 
                            variable bm_provider
                            
--meta_provider_id          The id of the cloud provider this test pertains
                            to. May also be specified using the environment 
                            variable bm_provider_id
                            
--meta_region               The compute service region this test pertains to. 
                            May also be specified using the environment 
                            variable bm_region
                            
--meta_resource_id          An optional benchmark resource identifiers. May 
                            also be specified using the environment variable 
                            bm_resource_id
                            
--meta_run_id               An optional benchmark run identifiers. May also be 
                            specified using the environment variable bm_run_id
                            
--meta_storage_config       Storage configuration descriptor. May also be 
                            specified using the environment variable 
                            bm_storage_config
                            
--meta_storage_volumes      Number of storage volumes attached to each HDFS 
                            node
                            
--meta_storage_volume_size  Size of each storage volume in GB
                            
--meta_test_id              Identifier for the test. May also be specified 
                            using the environment variable bm_test_id
                            
--no_purge                  If set, 'teragen_dir', 'terasort_dir' and 
                            'teravalidate_dir' will not be deleted upon test 
                            completion
                            
--output                    The output directory to use for writing test data 
                            (logs and artifacts). If not specified, the current 
                            working directory will be used
                            
--tera_args                 Optional -D arguments for teragen, terasort and 
                            teravalidate - multiple OK
                            e.g. "dfs.block.size=134217728". Any of the *_args
                            parameters may contain the following tokens:
                              {cpus} : number of CPU cores on the host
                              {nodes}: number of nodes in the hdfs cluster
                                       (per meta_hdfs_nodes)
                              {rows}:  number of rows (teragen_rows)
                              {gb}:    size of rows in GB
                            Additionally, values may be an expression:
                              e.g. mapred.map.tasks={cpus}*{nodes}
                            or a ternary expression - reduce jobs are 64GB but 
                            at least equal to the number of cluster nodes:
                              e.g. mapreduce.job.reduces={nodes}>({gb}/64)?{nodes}:({gb}/64)
                            
--teragen_args              Optional -D arguments for teragen - multiple OK
                            e.g. "dfs.block.size=134217728"
                              
--teragen_balance           when set, the hdfs cluster will be balanced 
                            to this threshold following execution of teragen 
                            (sudo -u hdfs hdfs balancer -threshold [teragen_balance])
                            User must have sudo privilege. The balance 
                            operation can be very slow - increase the 
                            hdfs-site.xml property 
                            dfs.datanode.balance.bandwidthPerSec from the 
                            default 8 Mb/s (1048576) something higher to 
                            increase balancing speed. Value should be between
                            3 and 50
                            
--teragen_dir               hdfs directory where teragen input should be 
                            written to - if it already exists, it will be 
                            deleted prior to testing. Default is 
                            'terasort-input'
                            
--teragen_rows              Number of 100 byte rows to generate - default is 
                            10 billion == 1 TB. This parameter will be 
                            validated against the available HDFS capacity 
                            prior to test execution
                            
--terasort_args             Optional -D arguments for terasort - multiple OK
                            e.g. "mapred.map.tasks=4"
                            
--terasort_dir              hdfs directory where terasort output should be 
                            written to - if it already exists, it will be 
                            deleted prior to testing. Default is 
                            'terasort-output'
                            
--teravalidate_args         Optional -D arguments for teravalidate - multiple 
                            OK
                            
--teravalidate_dir          hdfs directory where teravalidate output should be 
                            written to - if it already exists, it will be 
                            deleted prior to testing. Default is 
                            'terasort-validate'
                            
--verbose                   Show verbose output
                            
                            
DEPENDENCIES
This benchmark has the following dependencies:

hadoop      Hadoop command - cluster should be provisioned and enabled for 
            the current user invoking run.sh
            
hdfs        Used to interact with hdfs

java        Java JDK

php         Test automation scripts (/usr/bin/php)

zip         Used to compress test artifacts


USAGE
# run 1 test iteration with some metadata
./run.sh --meta_compute_service_id aws:ec2 --meta_instance_id c3.xlarge --meta_region us-east-1 --meta_test_id aws-1214

# run with explicit hadoop_examples_jar
./run.sh --hadoop_examples_jar /usr/lib/hadoop-0.20-mapreduce/hadoop-examples.jar

# run 3 test iterations using a specific output directory
for i in {1..3}; do mkdir -p ~/terasort-testing/$i; ./run.sh --output ~/terasort-testing/$i; done


EXIT CODES:
  0 test successful
  1 test failed

EOF
  exit
elif [ -f "/usr/bin/php" ]; then
  $( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )/lib/run.php $@
  exit $?
else
  echo "Error: missing dependency php-cli (/usr/bin/php)"
  exit 1
fi

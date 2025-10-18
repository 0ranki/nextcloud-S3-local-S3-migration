<?php
/* *********************************************************************************** */
/*        2023 code created by Eesger Toering / knoop.frl / geoarchive.eu              */
/*     Like the work? You'll be surprised how much time goes into things like this..   */
/*                            be my hero, support my work,                             */
/*                     https://paypal.me/eesgertoering                                 */
/*                     https://www.geef.nl/en/donate?action=15544                      */
/* *********************************************************************************** */

/* 2025 forked by Jarno Rankinen from https://github.com/GeoArchive/nextcloud-S3-local-S3-migration */

# best practice: run the script as the cloud-user!!
# sudo -u clouduser php74 -d memory_limit=1024M /var/www/vhost/nextcloud/s3tolocal-postgres.php

# runuser -u clouduser -- composer require aws/aws-sdk-php
use Aws\S3\S3Client;

echo "\n#########################################################################################";
echo "\n Migration tool for Nextcloud S3 to local version 0.34\n";
echo "\n Reading config...";

// Note: Preferably use absolute path without trailing directory separators
$PATH_BASE      = '/var/www/html';          // Path to the base of the main Nextcloud directory

$PATH_NEXTCLOUD = $PATH_BASE;               // Path of the public Nextcloud directory
$PATH_DATA      = $PATH_BASE.'/data';       // Path of the new Nextcloud data directory
$PATH_DATA_BKP  = $PATH_BASE.'/data.bkp';   // Path of a previous migration.. to speed things up.. (manually move a previous migration here!!)
$PATH_BACKUP    = $PATH_BASE.'/bak';        // Path for backup of PostgreSQL database

// don't forget this one -.
// $OCC_BASE       = 'sudo -u clouduser php82 -d memory_limit=1024M '.$PATH_NEXTCLOUD.'/occ ';
$OCC_BASE       = 'php -d memory_limit=1024M '.$PATH_NEXTCLOUD.'/occ ';
// fill this variable ONLY when you are unable to run the 'occ' command above as the clouduser 
// example 'clouduser:group';
$CLOUDUSER      = '';

$TEST = 1;
// set to 0 for LIVE!!
// set to 1 just get all the data to local, NO database chainges
// set to user name for single user (migration) test

$NON_EMPTY_TARGET_OK = 1;

$PATH_DATA_LOCAL_EXISTS_OK = 1; //default 0 !! Only set to 1 if you're sure..

$NR_OF_COPY_ERRORS_OK = 8;

// DB configuration is read from config.php.
// Postgres prompts for password, so using these is not
$SQL_DUMP_USER = '';
$SQL_DUMP_PASS = '';

if ($NON_EMPTY_TARGET_OK
 || !empty($TEST)) {
  echo "\n\n#########################################################################################";
  echo !$NON_EMPTY_TARGET_OK ? '' : "\nWARNING: deleted files since a previous copy will not get NOT removed!";
  echo empty($TEST)          ? '' : "\nWARNING: you are in test mode (".$TEST.")";
  echo "\nContinue?";
  $getLine = '';
  while ($getLine == ''): $getLine = fgets( fopen("php://stdin","r") ); endwhile;
}

echo "\n\n#########################################################################################";
echo "\nSetting up S3 migration to local...\n";

// Autoload
require_once(dirname(__FILE__).'/3rdparty/autoload.php');

if (empty($TEST)) {
  // Activate maintenance mode
  $process = occ($OCC_BASE,'maintenance:mode --on');
  echo $process;
  
  if (strpos($process, "\nMaintenance mode") == 0
   && strpos($process, 'Maintenance mode already enabled') == 0) {
    echo " could not set..  ouput command: ".$process."\n\n";
    die;
  }
}

echo "\nfirst load the nextcloud config...";
include($PATH_NEXTCLOUD.'/config/config.php');

echo "\nconnect to sql-database...";
// Database setup
$pg_connection = pg_connect("host={$CONFIG['dbhost']} user={$CONFIG['dbuser']} password={$CONFIG['dbpassword']} dbname={$CONFIG['dbname']} options='--client_encoding=UTF8'");

################################################################################ checks #
$LOCAL_STORE_ID = 0;
if ($result = pg_query($pg_connection, "SELECT * FROM oc_storages WHERE id = 'local::$PATH_DATA/'")) {
  while ($row = pg_fetch_assoc($result)) {
    echo "\nERROR: there already is a oc_storages record with 'local::$PATH_DATA/' (id:".$row['numeric_id'].")";
  }
  if (pg_num_rows($result) > 0) {
    echo "\nClean this up (check oc_filecache, oc_filecache_extended, oc_filecache_locks and more?)";
    echo "\n(keep one, or none.. check this source for some tips..)";
    # those tips.... :
    # SELECT `oc_filecache_extended`.`fileid`, `oc_filecache`.`storage` FROM `oc_filecache_extended` LEFT JOIN `oc_filecache` ON `oc_filecache`.`fileid` = `oc_filecache_extended`.`fileid`
    # SELECT `oc_file_metadata`.`id`, `oc_filecache`.`storage` FROM `oc_file_metadata` LEFT JOIN `oc_filecache` ON `oc_filecache`.`fileid` = `oc_file_metadata`.`id`
  }
  if (pg_num_rows($result) > 1) {
    echo "\nERROR: Multiple 'local::$PATH_DATA', it's an accident waiting to happen!!\n";
    die;
  }
  else if (pg_num_rows($result) == 1) {
    echo "\nWARNING/ERROR: Clean up `oc_filecache`";
    if (!$PATH_DATA_LOCAL_EXISTS_OK) {
      echo " and then set \$PATH_DATA_LOCAL_EXISTS_OK to 1 (be carefull!!!)\n";
    }
    if (!$PATH_DATA_LOCAL_EXISTS_OK) {
      if (empty($TEST)) {
        die;
      } else {
        echo "We're in 'test mode', so we will continue.. but upon 'live' it'll fail!!\n";
      }
    }
    $row = pg_fetch_assoc($result);
    $LOCAL_STORE_ID = $row['numeric_id']; // for creative rename command..
    echo "\nThe local store  id $LOCAL_STORE_ID";
  }
}

$OBJECT_STORE_ID = 0;
if ($result = pg_query("SELECT * FROM oc_storages WHERE id LIKE 'object::store:%'")) {
  if (pg_num_rows($result)>1) {
    echo "\nMultiple 'object::store:' clean this up, it's an accident waiting to happen!!\n";
    die;
  }
  else if (pg_num_rows($result) == 0) {
    echo "\nNo 'object::store:' No S3 storage defined!?\n";
    die;
  }
  else {
    $row = pg_fetch_assoc($result);
    $OBJECT_STORE_ID = $row['numeric_id']; // for creative rename command..
    echo "\nThe object store id is $OBJECT_STORE_ID";
  }
}

echo "\ndatabase backup...";
if (!is_dir($PATH_BACKUP)) { echo "$PATH_BACKUP folder does not exist\n"; die; }

$rtr_code = 0;
$output = null;
$process = exec('POSTGRES_PASSWORD="'.escapeshellcmd( empty($SQL_DUMP_PASS)?$CONFIG['dbpassword']:$SQL_DUMP_PASS ).'" pg_dump'.
                      ' -U '.(empty($SQL_DUMP_USER)?$CONFIG['dbuser']:$SQL_DUMP_USER).
                      ' -h '.$CONFIG['dbhost'].
                      ' > '.$PATH_BACKUP . DIRECTORY_SEPARATOR . 'backup.sql',  $output,$rtr_code);

if ($rtr_code != 0) {
  echo "sql dump error\n";
  die;
} else {
  echo "\n(to restore, drop and recreate the database, then:\n`psql -U ".(empty($SQL_DUMP_USER)?$CONFIG['dbuser']:$SQL_DUMP_USER)." ".$CONFIG['dbname']." < backup.sql`)\n";
}

echo "\nbackup config.php...";
$copy = 1;
if(file_exists($PATH_BACKUP.'/config.php')){
  if (filemtime($PATH_NEXTCLOUD.'/config/config.php') > filemtime($PATH_BACKUP.'/config.php') ) {
    unlink($PATH_BACKUP.'/config.php');
  }
  else {
    echo 'not needed';
    $copy = 0;
  }
}
if ($copy) {
  copy($PATH_NEXTCLOUD.'/config/config.php', $PATH_BACKUP.'/config.php');
}

echo "\nconnect to S3...";
$bucket = $CONFIG['objectstore']['arguments']['bucket'];
$proto  = isset($CONFIG['objectstore']['arguments']['use_ssl']) ? $CONFIG['objectstore']['arguments']['use_ssl'] : true;
$proto  = $proto ? 'https' : 'http';  // ? added line
$port   = isset($CONFIG['objectstore']['arguments']['port']) ? ':'.$CONFIG['objectstore']['arguments']['port'] : '';
$region = empty($CONFIG['objectstore']['arguments']['region']) ? 'eu-west-1' : $CONFIG['objectstore']['arguments']['region']; // For non-AWS S3 providers, region may not be used, but the PHP client needs it

if ($CONFIG['objectstore']['arguments']['use_path_style']) {
  $s3 = new S3Client([
    'version' => 'latest',
    'endpoint' => $proto.'://'.$CONFIG['objectstore']['arguments']['hostname'].$port.'/'.$bucket,
    'bucket_endpoint' => !isset($CONFIG['objectstore']['arguments']['bucket_endpoint']) ? true : $CONFIG['objectstore']['arguments']['bucket_endpoint'],
    'use_path_style_endpoint' => !isset($CONFIG['objectstore']['arguments']['use_path_style_endpoint']) ? true : $CONFIG['objectstore']['arguments']['use_path_style_endpoint'],
    'region'  => $region,
    'credentials' => [
      'key' => $CONFIG['objectstore']['arguments']['key'],
      'secret' => $CONFIG['objectstore']['arguments']['secret'],
    ],
  ]);
} else {
  $s3 = new S3Client([
    'version' => 'latest',
    'endpoint' => $proto.'://'.$bucket.'.'.$CONFIG['objectstore']['arguments']['hostname'].$port,
    'bucket_endpoint' => !isset($CONFIG['objectstore']['arguments']['bucket_endpoint']) ? true : $CONFIG['objectstore']['arguments']['bucket_endpoint'],
    'region'  => $region,
    'credentials' => [
      'key' => $CONFIG['objectstore']['arguments']['key'],
      'secret' => $CONFIG['objectstore']['arguments']['secret'],
    ],
  ]);
}

// Check that new Nextcloud data directory is empty
if (count(scandir($PATH_DATA)) != 2) {
  echo "\nThe new Nextcloud data directory is not empty..";
  if (!$NON_EMPTY_TARGET_OK) {
    echo "\nAborting script\n";
    die;
  } else {
    echo "\nWARNING: deleted files since previous copy are NOT removed! (take a look at the option '\$PATH_DATA_BKP')\n";
  }
}

if (!is_dir($PATH_DATA_BKP)) { echo "\$PATH_DATA_BKP folder does not exist\n"; die; }


echo "\n#########################################################################################";
echo "\nSetting everything up finished ##########################################################\n";

echo "\nCreating folder structure started... ";

if ($result = pg_query("SELECT ST.id, FC.fileid, FC.path, FC.storage_mtime FROM".
                             " oc_filecache as FC,".
                             " oc_storages  as ST,".
                             " oc_mimetypes as MT".
                             " WHERE ST.numeric_id = FC.storage".
                              " AND ST.id LIKE 'object::%'".
                              " AND FC.mimetype = MT.id".
                              " AND MT.mimetype = 'httpd/unix-directory'")) {
  
  // Init progress
  $complete = pg_num_rows($result);
  $prev     = '';
  $current  = 0;
  
  while ($row = pg_fetch_assoc($result)) {
    $current++;
    try {
      // Determine correct path
      if (substr($row['id'], 0, 13) != 'object::user:') {
        $path = $PATH_DATA . DIRECTORY_SEPARATOR . $row['path'];
      } else {
        $path = $PATH_DATA . DIRECTORY_SEPARATOR . substr($row['id'], 13) . DIRECTORY_SEPARATOR . $row['path'];
      }
      // Create folder (if it doesn't already exist)
      if (!file_exists($path)) {
        mkdir($path, 0777, true);
      }
      #echo "\n".$path."\t";
      touch($path, $row['storage_mtime']);
    } catch (Exception $e) {
      echo "    Failed to create: ".$row['path']." (".$e->getMessage().")\n";
      $flag = false;
    }
    // Update progress
    $new = floor($current/$complete*100).'%';
    if ($prev != $new ) {
      echo str_repeat(chr(8) , strlen($prev) );
      $prev = $current+1 >= $complete ? ' DONE ' : $new;
      echo $prev;
    }
  }
  pg_free_result($result);
}

echo "\nCreating folder structure finished\n";

echo "Copying files started... ";
$error_copy = '';

$users      = array();

if ($result = pg_query("SELECT ST.id, FC.fileid, FC.path, FC.storage_mtime, FC.size, FC.storage FROM".
                             " oc_filecache AS FC,".
                             " oc_storages  AS ST,".
                             " oc_mimetypes AS MT".
                             " WHERE ST.numeric_id = FC.storage".
                              " AND ST.id LIKE 'object::%'".
                              " AND FC.mimetype = MT.id".
                              " AND MT.mimetype != 'httpd/unix-directory'".
                             " ORDER BY ST.id ASC")) {

  // Init progress
  $complete = pg_num_rows($result);
  $current  = 0;
  $prev     = '';
  $prevUser = '';

  // Use the same options array for S3 every getobject command, only alter Key and SaveAs for each bucket
  $s3GetOpts = array(
    'Bucket' => $bucket,
    'Key'    => 'urn:oid:XXX',
    'SaveAs' => '/dev/null',
  );
  if (!empty($CONFIG['objectstore']['arguments']['sse_c_key'])) {
    // Add parameters to handle SSE-C encryption, if enabled in config.php
    $s3GetOpts['SSECustomerAlgorithm'] = 'AES256';
    $s3GetOpts['SSECustomerKey'] = base64_decode($CONFIG['objectstore']['arguments']['sse_c_key']);
    $s3GetOpts['SSECustomerKeyMD5'] = md5(base64_decode($CONFIG['objectstore']['arguments']['sse_c_key'] ),true);
  }

  while ($row = pg_fetch_assoc($result)) {
    $current++;
    try {
      // Determine correct path
      if (substr($row['id'], 0, 13) != 'object::user:') {
        $path = $PATH_DATA . DIRECTORY_SEPARATOR . $row['path'];
      } else {
        $path = $PATH_DATA . DIRECTORY_SEPARATOR . substr($row['id'], 13) . DIRECTORY_SEPARATOR . $row['path'];
      }
      $user = substr($path, strlen($PATH_DATA. DIRECTORY_SEPARATOR));
      $user = substr($user,0,strpos($user,DIRECTORY_SEPARATOR));
      $users[ $user ] = $row['storage'];

      # just for one user? set test = appdata_oczvcie795w3 (system wil not go to maintenance nor change database, just test and copy data!!)
      if (is_numeric($TEST) || $TEST == $user ) {
        #echo "\n".$path."\t".$row['storage_mtime'];
        $copy = 1;
        if(file_exists($path) && is_file($path)){
          if ($row['storage_mtime'] > filemtime($path) ) {
            unlink($path);
          }
          else { $copy = 0;}#echo '.'; }
        }
        if ($copy) {
          $path_bkp = str_replace($PATH_DATA,
                                  $PATH_DATA_BKP,
                                  $path);
          if (file_exists($path_bkp) && is_file($path_bkp)
           && $row['storage_mtime'] == filemtime($path_bkp) ) {
            if (rename($path_bkp,
                       $path) ) {
              $copy = 0;
            } else {
              echo "\nmove failed!?\n";
              exit;
            }
            #echo ':';
          }
        }
        if ($copy) {
          $s3GetOpts['Key'] = 'urn:oid:'.$row['fileid'];
          $s3GetOpts['SaveAs'] = $path;
          $s3->getObject($s3GetOpts);
          // Also set modification time
          touch($path, $row['storage_mtime']);
        }
      }
    } catch (Exception $e) {
      if(file_exists($path) && is_file($path) ){
        unlink($path);
      }
      echo "\n#########################################################################################";
      echo "\nFailed to transfer: $row[fileid] (".$e->getMessage().")\n";
      echo "\ntarget: ".$path."\n";
      echo "datadump of database record:\n";
      print_r($row);
      $error_copy.= $path."\n";
      $prev = '';
    }
    // Update progress
    if ($prevUser != $user) {
      echo "\n";
      $prevUser = $user;
    }
    $new = sprintf('%.2f',$current/$complete*100).'% (now at user '.$user.')';
    if ($prev != $new ) {
      echo str_repeat(chr(8) , strlen($prev) );
      $prev = $current+1 >= $complete ? ' DONE ' : $new;
      echo $prev;
    }
  }
  pg_free_result($result);
}
echo "\n";


if (!empty($error_copy)) {
  echo "\n#########################################################################################";
  $error_count = substr_count($error_copy,"\n");
  echo "\nCopying of ".$error_count." files failed:\n".$error_copy."\n\n";
  if ($error_count > $NR_OF_COPY_ERRORS_OK ) {
    echo "Aborting script\n";
    die;
  } else {
    echo "\nContinue?";
    $getLine = '';
    while ($getLine == ''): $getLine = fgets( fopen("php://stdin","r") ); endwhile;
  }
}

echo "\nCopying files finished";

if (!empty($CLOUDUSER)) {
  echo "\n\nSet the correct owner of the data folder..";
  echo occ('','chown -R '.$CLOUDUSER.' '.$PATH_DATA);
  echo "\n";
}

if (empty($TEST)) {
  echo "\n#########################################################################################";
  echo "\nModifying database started...\n";
  
  pg_query("UPDATE oc_storages SET id=CONCAT('home::', SUBSTRING_INDEX(oc_storages.id,':',-1)) WHERE oc_storages.id LIKE 'object::user:%'");
  
  //rename command
  if ($LOCAL_STORE_ID == 0
   || $OBJECT_STORE_ID== 0) { // standard rename
    pg_query("UPDATE oc_storages SET id='local::$PATH_DATA/' WHERE oc_storages.id LIKE 'object::store:%'");
  } else {
    pg_query("UPDATE oc_filecache SET storage = '".$LOCAL_STORE_ID."' WHERE storage = '".$OBJECT_STORE_ID."'");
    pg_query("DELETE FROM oc_storages WHERE oc_storages.numeric_id = ".$OBJECT_STORE_ID);
  }

  foreach ($users as $key => $value) {
    $result = pg_query("UPDATE oc_mounts SET mount_provider_class = REPLACE(mount_provider_class, 'ObjectHomeMountProvider', 'LocalHomeMountProvider') WHERE user_id = '".$key."'");
    if (pg_affected_rows($result) == 1) {
      echo $dashLine."\n-Changed mount provider class off ".$key." from home to object";
      $dashLine = '';
    }
  }
  
  echo "\nModifying database finished";
  
  echo "\nDoing final adjustments started...";

  echo "\nDeactivate maintenance mode...";
  echo occ($OCC_BASE,'maintenance:mode --off');

  echo "\nUpdate config file...";
  echo occ($OCC_BASE,'config:system:set datadirectory --value="'.$PATH_DATA.'"');

  echo "\nRemove S3 stuff from config file...";
  echo occ($OCC_BASE,'config:system:delete objectstore');
  if (file_exists($PATH_NEXTCLOUD.'/config/storage.config.php')) {
    echo "\nrename /config/storage.config.php...";
    rename($PATH_NEXTCLOUD.'/config/storage.config.php',
           $PATH_NEXTCLOUD.'/config/storage.config.bak');
  }

  
  echo "\nRunning cleanup (should not be necessary but cannot hurt)...";
  echo occ($OCC_BASE,'files:cleanup');

  echo "\nRunning scan (should not be necessary but cannot hurt)...";
  echo occ($OCC_BASE,'files:scan --all');
  
  echo "\nDoing final adjustments finished";
  
  echo "\n\nYou are good to go!\n";
} else {
  echo "\n\ndone testing..\n";
}

#########################################################################################
function occ($OCC_BASE,$OCC_COMMAND) {
  $result = "\nset  ".$OCC_COMMAND.":\n";

  ob_start();
  passthru($OCC_BASE . $OCC_COMMAND);
  $process = ob_get_contents();
  ob_end_clean(); //Use this instead of ob_flush()
  
  return $result.$process."\n";
}

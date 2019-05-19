<?php
    /**
     *  Define the name of zip file to work with
     */
    $zip_name = 'web.zip';
    /**
     *  Define name of directory with zip file
     *  Zip file will be extracted within this directory
     *  After extraction files will be moved one directory up
     */
    $working_dir = '_drupal_deploy';
    /**
     *  Directories and files to remove
     */
    $remove_dirs_and_files = [
        //Directories
        '.idea',
        'sites/default/files',
        //Files
        'sites/default/settings.php',
        'drupal_deploy.php',
    ];
    /**
     * These files or directories won't be moved one directory up
     * These can only be located in root dir of this script
     */
    $files_not_to_deploy = [
        $zip_name,
        'sites',
    ];

    /**
     * @param $dir_path
     * Recursive function for deleting whole directories
     */
    function deleteDir($dir_path) {
        /**
         * Scans dir and removes '.' and '..'
         */
        $dir_contents = array_diff(scandir($dir_path), array('..', '.'));

        /**
         * Delete all content of the directory
         */
        foreach ($dir_contents as $dir_content){
            if (is_dir($dir_path.'/'.$dir_content))
                deleteDir($dir_path.'/'.$dir_content);
            else
                unlink($dir_path.'/'.$dir_content);
        }
        rmdir($dir_path);
    }

    /**
     * Prepare working directory
     */
    if (!file_exists($working_dir)) {
      mkdir($working_dir, 0755, true);
    }

    /**
     * Opens and extracts .zip archive in current folder to working directory
     */
    $zip = new ZipArchive;

    if ($zip->open($zip_name) === true){
        $zip->extractTo(__DIR__.'/'.$working_dir);
        $zip->close();
    }
    else {
        header('HTTP/1.1 420 Zip file not found');
        die('Zip file not found');
    }

    /**
     * Check if source code is in subdirectory (if there is just one directory we assume code is in it)
     */
    $zip_subdir = '';
    $contents = array_diff(scandir(__DIR__.'/'.$working_dir), array('..', '.'));
    $drupal_dirs = ['core','libraries','modules','profiles','sites','themes','vendor'];
    if (count($contents)==1 && !in_array($contents[2],$drupal_dirs)) {
      $zip_subdir = '/'.$contents[2];
    }

    /**
     * Checks array of files and dirs to delete.
     * If they exist it deletes them
     */
    foreach ($remove_dirs_and_files as $remove_path){
        if (file_exists(__DIR__.'/'.$working_dir.$zip_subdir.'/'.$remove_path)){
          if (is_dir(__DIR__.'/'.$working_dir.$zip_subdir.'/'.$remove_path))
            deleteDir(__DIR__.'/'.$working_dir.$zip_subdir.'/'.$remove_path);
          else
            unlink(__DIR__.'/'.$working_dir.$zip_subdir.'/'.$remove_path);
        }
    }

    /**
     * Moves every file and directory to web root
     * Except files and directories declared in $files_not_to_deploy
     */
    $contents = array_diff(scandir(__DIR__.'/'.$working_dir.$zip_subdir), array('..', '.'));
    if ($contents) {
      foreach ($contents as $content) {
        if (in_array($content, $files_not_to_deploy)) {
          continue;
        }
        // DONT delete sites directory, because there are all media
        if ($content != 'sites' && is_dir(__DIR__ . '/' . $content)) {
          deleteDir(__DIR__ . '/' . $content);
        }
        rename(__DIR__ . '/' . $working_dir . $zip_subdir . '/' . $content, __DIR__ . '/' . $content);
      }
    }

    /**
     * Moves every file in specific sites directory
     */
    $contents = array_diff(scandir(__DIR__.'/'.$working_dir.$zip_subdir.'/sites'), array('..', '.'));
    if (!is_dir(__DIR__ . '/sites' )) {
      mkdir(__DIR__ . '/sites', 0755);
    }
    if ($contents) {
      foreach ($contents as $content) {
        if (!is_dir(__DIR__ . '/sites/' . $content)) {
          rename(__DIR__ . '/' . $working_dir . $zip_subdir . '/sites/' . $content, __DIR__ . '/sites/' . $content);
        }
      }
    }

    /**
     * clear everything after deploy
     */
    deleteDir($working_dir);
    unlink($zip_name);

    header('HTTP/1.1 200 OK');
    die('OK');

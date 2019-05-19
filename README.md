These scripts are example how it is possible to deploy Drupal 8 automatically to standard FTP

# How to use it
## drupal_deploy.php
This script has to be uploaded in web root so it can be called simple via URL as this (just replace example.com with your domain)
```sh
http://www.example.com/drupal_deploy.php
```
this script will look for web.zip file in the web root and if it find it, it will extract all its content to new directory and when it is done it will replace all files and directories in web root (so it will delete directory and move there new unzipped direcoty) so code change is very quick and you will avoid long time when code files and not consistens during long upload via FTP. 

Check the script for additional settings at the start

## drupal_update.php
This script has to be uploaded in web root so it can be called simple via URL as this (just replace example.com with your domain)
```sh
http://www.example.com/drupal_update.php
```
this script will check if there are some new updates and post updates in drupal which needs to be done. When it find any, it will done 1 and return code 206. When there are no more update to be done, it will just clear cache and return code 200. this way it can be called recursively until all updates has been done.  
```IMPORTANT NOTE: this script will check for update also for anonymous users regarless what you set in settings.php. It is important to keep it this way so it will work when some server call it during automatic updates. If you are not OK with it, you should rename it so nobody know the name of the script or implement custom protections. ``

## server_script_example.txt
This is just an example how could server script look like to automatically deploy and update your site. this script also take care that only neccesary themes and modules are deployed to each site.

# Main idea
Main idea of deploy like this is to keep a lot of sites up to date with minimal effort. We do it that we have one GIT repository for a lot of small sites build on Drupal 8. On yur local machine you can update just this one repository and then run script for each site to update it automatically. 
We have UNIVERSAL theme which solve common issues and have some settings (nice mobile menus, all messages in popups, fixed menu when scroll, option to add tracking scripts to site, ...) and then each small web have own subtheme of this UNIVERSAL theme.
In ```modules/custom `` we keep univercal custom modules usefull for each site and in ```modules/custom/site_specific `` we have modules which are usefull just for a specific site.
Server script will this way can easily deploy just what is neccesary.
Then we use Jenkins where we have job for deploy for each site separately. So you can just hit the button and it will deploy latest code. And we have there option to run deploy for all sites. So you in case of core update you can update everything just with one click.

# Ideas for improvements
### Add restore script after unsuccessful deploy
 - change drupal_deploy.php so it would not replace all directories but move what is on web to backup directory and create also DB backup
 - add to server script also some simple check if site is runnig after update (try to call some special URL like /user or homepage or somethig)
 - create drupal_restore.php script which would replace all code back from backup and also import DB from backup
 - in case the deploy will fail or the check will fail, server would call drupal_restore.php and maybe send some notification
 
 ### make drupal_deploy.php also callable recursively 
  - if you put to the repository a lot of contrib modules with a lot of dependencies, on some FTP servers the unzip process could take long and ended up with timeout so the script could be change similar way as drupal_update.php is done. For example it will check if there is another .zip files for unziping (like web1.zip, web2.zip, etc) and return 206 if it found it. and if there is no more files to unzip, it will replace the code files and return 200
  - also server script would need to be changed so it will ziped it to more files and call drupal_deploy.php recursively

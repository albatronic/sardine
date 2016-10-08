<?php
/**
 * ACTIVAR EL AUTOLOADER DE CLASES Y FICHEROS A INCLUIR
 * User: Sergio Pérez <sperez@trevenque.es>
 * Date: 14/4/16
 * Time: 10:39
 */

include_once dirname(__FILE__) . "/Autoloader.php";

Autoloader::setCacheFilePath(PATH . 'modules/_cache/class_path_cache.txt');
Autoloader::excludeFolderNamesMatchingRegex('/^CVS|\..*$/');
Autoloader::setClassPaths(array(
    dirname(__FILE__) .'/',
    PATH_WEB_ABS . 'models/',
    PATH_WEB_ABS . 'cup/lib/',
));
spl_autoload_register(array('Autoloader', 'loadClass'));

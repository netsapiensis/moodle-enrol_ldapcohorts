<?php

defined('MOODLE_INTERNAL') || die();

if (ob_get_level() == 0) ob_start();

if ($ADMIN->fulltree) {

    $plugin = enrol_get_plugin('ldapcohorts');

    if (class_exists('enrol_ldapcohorts_plugin') && $plugin instanceof enrol_ldapcohorts_plugin) { //ok

        if (core_useragent::check_browser_version('MSIE')) {
            //ugly IE hack to work around downloading instead of viewing
            @header('Content-Type: text/html; charset=utf-8');
            echo "<xmp>"; //<pre> is not good enough for us here
        } else {
            //send proper plaintext header
            @header('Content-Type: text/plain; charset=utf-8');
        }
        set_time_limit(0);
        /// increase memory limit
        raise_memory_limit(MEMORY_EXTRA);

        echo str_pad('', 4096) . "\n";

        ob_flush();
        flush();

        $plugin->cron(false);

        // finish the IE hack
        if (core_useragent::check_browser_version('MSIE')) {
            echo "</xmp>";
        }
        exit();
    }

}

exit();
<?php
add_action('plugins_loaded', function () {
    $base = dirname(__DIR__) . '/plugin-update-checker';
    $bootstrap = $base . '/plugin-update-checker.php';
    if ( ! file_exists($bootstrap) ) return;
    require_once $bootstrap;

    // Pick factory (namespaced v5 → legacy v5 → v4).
    if ( class_exists('\YahnisElsts\PluginUpdateChecker\v5\PucFactory') ) {
        $factory = '\YahnisElsts\PluginUpdateChecker\v5\PucFactory';
    } elseif ( class_exists('Puc_v5_Factory') ) {
        $factory = 'Puc_v5_Factory';
    } elseif ( class_exists('Puc_v4_Factory') ) {
        $factory = 'Puc_v4_Factory';
    } else {
        return;
    }

    $updater = $factory::buildUpdateChecker(
        'https://github.com/berendsohn-ag/bag-digitalservice/',
        BDS_FILE,
        plugin_basename(BDS_FILE)
    );

    if ( method_exists($updater, 'setBranch') ) {
        $updater->setBranch('main');
    }

    // Prefer release assets if you attach ZIPs to releases
    if ( method_exists($updater, 'getVcsApi') ) {
        $api = $updater->getVcsApi();
        if ( $api && method_exists($api, 'enableReleaseAssets') ) {
            $api->enableReleaseAssets();
        }
    }

    // “Check for updates” link in Plugins list
    if ( class_exists('\Puc\v5p6\Plugin\Ui') ) {
        new \Puc\v5p6\Plugin\Ui($updater);
    } elseif ( class_exists('\Puc\v5\Plugin\Ui') ) {
        new \Puc\v5\Plugin\Ui($updater);
    }

}, 5);

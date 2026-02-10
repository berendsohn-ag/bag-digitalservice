<?php
add_action('plugins_loaded', function () {

    // Pfad zur PUC Library
    $bootstrap = dirname(__DIR__) . '/plugin-update-checker/plugin-update-checker.php';
    if ( ! file_exists($bootstrap) ) return;
    require_once $bootstrap;

    // Nimm die v5 Factory (die erkennt GitHub sauber)
    if ( ! class_exists('\YahnisElsts\PluginUpdateChecker\v5\PucFactory') ) return;

    // ✅ WICHTIG: Repo-URL OHNE /tree/main und am besten ohne trailing slash
    $repoUrl = 'https://github.com/berendsohn-ag/bag-digitalservice';

    $updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        $repoUrl,
        BDS_FILE,
        plugin_basename(BDS_FILE)
    );

    // Branch
    if ( method_exists($updater, 'setBranch') ) {
        $updater->setBranch('main');
    }

    // ✅ Release Assets bevorzugen (wenn du Releases mit ZIP verwendest)
    if ( method_exists($updater, 'getVcsApi') ) {
        $api = $updater->getVcsApi();
        if ( $api && method_exists($api, 'enableReleaseAssets') ) {
            $api->enableReleaseAssets();
        }
    }

    // ✅ OPTIONAL: Auth nur wenn definiert (hilft gegen 403 / Rate-Limit)
    // Wenn du KEIN Token willst: einfach NICHT definieren.
    if ( defined('BDS_UPDATER_TOKEN') && BDS_UPDATER_TOKEN && method_exists($updater, 'setAuthentication') ) {
        $updater->setAuthentication(BDS_UPDATER_TOKEN);
    }

    // UI-Link “Check for updates”
    if ( class_exists('\Puc\v5p6\Plugin\Ui') ) {
        new \Puc\v5p6\Plugin\Ui($updater);
    } elseif ( class_exists('\Puc\v5\Plugin\Ui') ) {
        new \Puc\v5\Plugin\Ui($updater);
    }

}, 5);

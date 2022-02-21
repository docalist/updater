# docalist/updater
Mise à jour d'une installation à partir d'un webhook.

Exemple d'utilisation :
```php
use Docalist\Updater\Updater;

require_once '../vendor/docalist/updater/Updater.php';  // Charge docalist/updater

Updater::update('exemple.org', [                        // Met à jour le site exemple.org
    'when' => 'push',                                   // Quand il y a un push
    'on'   => 'main',                                   // Sur la branche main
    'from' => 'https://github.com/exemple/site',        // Du dépôt github exemple/site
    'do'   => [                                         // En exécutant les commandes suivantes
        "git pull -v",
        "bin/composer install",
        "bin/console doctrine:migrations:migrate --no-interaction",
    ],
    'in'   => '/var/www/site'                           // Dans le répertoire /var/www/site
]);
```

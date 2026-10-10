<?php
declare(strict_types=1);

// Vorlage für die PayPal-Konfiguration auf dem IONOS-Webspace.
// NICHT als echte Konfiguration verwenden und niemals echte Secrets in GitHub speichern.
// Auf IONOS in /public/api als paypal-config.php anlegen. Die api/.htaccess blockiert direkten Webzugriff.
return [
    'mode' => 'sandbox',
    'client_id' => 'SANDBOX_CLIENT_ID_HIER_EINTRAGEN',
    'client_secret' => 'SANDBOX_CLIENT_SECRET_HIER_EINTRAGEN',
];

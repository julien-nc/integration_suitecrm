<?php
/**
 * Nextcloud - SuiteCRM
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

return [
    'routes' => [
        ['name' => 'config#oauthConnect', 'url' => '/oauth-connect', 'verb' => 'POST'],
        ['name' => 'config#setConfig', 'url' => '/config', 'verb' => 'PUT'],
        ['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],
        ['name' => 'suiteCRMAPI#getReminders', 'url' => '/reminders', 'verb' => 'GET'],
        ['name' => 'suiteCRMAPI#getSuiteCRMUrl', 'url' => '/url', 'verb' => 'GET'],
        ['name' => 'suiteCRMAPI#getSuiteCRMAvatar', 'url' => '/avatar', 'verb' => 'GET'],
    ]
];

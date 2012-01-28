<?php
/**
 *
 * ThinkUp/webapp/plugins/statusnet/controller/class.StatusNetAuthController.php
 *
 * Copyright (c) 2009-2012 Gina Trapani
 *
 * LICENSE:
 *
 * This file is part of ThinkUp (http://thinkupapp.com).
 *
 * ThinkUp is free software: you can redistribute it and/or modify it under the terms of the GNU General Public
 * License as published by the Free Software Foundation, either version 2 of the License, or (at your option) any
 * later version.
 *
 * ThinkUp is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more
 * details.
 *
 * You should have received a copy of the GNU General Public License along with ThinkUp.  If not, see
 * <http://www.gnu.org/licenses/>.
 *
 * StatusNet Auth Controller
 * Save the OAuth tokens for StatusNet account authorization.
 * @license http://www.gnu.org/licenses/gpl.html
 * @copyright 2009-2012 Gina Trapani
 * @author Gina Trapani <ginatrapani[at]gmail[dot]com>
 *
 */
class StatusNetAuthController extends ThinkUpAuthController {
    /**
     *
     * @var boolean
     */
    var $is_missing_param = false;

    public function __construct($session_started=false) {
        parent::__construct($session_started);
        $config = Config::getInstance();
        Utils::defineConstants();
        $this->setViewTemplate(THINKUP_WEBAPP_PATH.'plugins/statusnet/view/auth.tpl');
        $this->setPageTitle('Authorizing Your StatusNet Account');
        if (!isset($_GET['oauth_token']) || $_GET['oauth_token'] == '' ) {
            $this->addInfoMessage('No OAuth token specified.');
            $this->is_missing_param = true;
        }
        if ( !SessionCache::isKeySet('oauth_request_token_secret') ||
        SessionCache::get('oauth_request_token_secret') == '' ) {
            $this->addInfoMessage('Secret token not set.');
            $this->is_missing_param = true;
        }
    }

    public function authControl() {
        if (!$this->is_missing_param) {
            $request_token = $_GET['oauth_token'];
            $request_token_secret = SessionCache::get('oauth_request_token_secret');

            // get oauth values
            $plugin_option_dao = DAOFactory::GetDAO('PluginOptionDAO');
            $options = $plugin_option_dao->getOptionsHash('statusnet', true); //get cached

            $to = new StatusNetOAuth($options['oauth_consumer_key']->option_value,
            $options['oauth_consumer_secret']->option_value, $request_token, $request_token_secret);

            $tok = $to->getAccessToken($_GET['oauth_verifier']);

            if (isset($tok['oauth_token']) && isset($tok['oauth_token_secret'])) {
                $api = new StatusNetAPIAccessorOAuth($tok['oauth_token'], $tok['oauth_token_secret'],
                $options['oauth_consumer_key']->option_value, $options['oauth_consumer_secret']->option_value,
                $options['num_statusnet_errors']->option_value, $options['max_api_calls_per_crawl']->option_value,
                false);

                $authed_statusnet_user = $api->verifyCredentials();
                //                echo "User ID: ". $authed_statusnet_user['user_id'];
                //                echo "User name: ". $authed_statusnet_user['user_name'];

                $owner_dao = DAOFactory::getDAO('OwnerDAO');
                $owner = $owner_dao->getByEmail($this->getLoggedInUser());

                if ((int) $authed_statusnet_user['user_id'] > 0) {
                    $instance_dao = DAOFactory::getDAO('StatusNetInstanceDAO');
                    $instance = $instance_dao->getByUsername($authed_statusnet_user['user_name'], 'statusnet');
                    $owner_instance_dao = DAOFactory::getDAO('OwnerInstanceDAO');
                    if (isset($instance)) {
                        $owner_instance = $owner_instance_dao->get($owner->id, $instance->id);
                        if ($owner_instance != null) {
                            $owner_instance_dao->updateTokens($owner->id, $instance->id, $tok['oauth_token'],
                            $tok['oauth_token_secret']);
                            $this->addSuccessMessage($authed_statusnet_user['user_name'].
                            " on StatusNet is already set up in ThinkUp! To add a different StatusNet account, ".
                            "log out of StatusNet.com in your browser and authorize ThinkUp again.");
                        } else {
                            if ($owner_instance_dao->insert($owner->id, $instance->id, $tok['oauth_token'],
                            $tok['oauth_token_secret'])) {
                                $this->addSuccessMessage("Success! ".$authed_statusnet_user['user_name'].
                                " on StatusNet has been added to ThinkUp!");
                            } else {
                                $this->addErrorMessage("Error: Could not create an owner instance.");
                            }
                        }
                    } else {
                        $instance_dao->insert($authed_statusnet_user['user_id'], $authed_statusnet_user['user_name']);
                        $instance = $instance_dao->getByUsername($authed_statusnet_user['user_name']);
                        if ($owner_instance_dao->insert( $owner->id, $instance->id, $tok['oauth_token'],
                        $tok['oauth_token_secret'])) {
                            $this->addSuccessMessage("Success! ".$authed_statusnet_user['user_name'].
                            " on StatusNet has been added to ThinkUp!");
                        } else {
                            $this->addErrorMessage("Error: Could not create an owner instance.");
                        }
                    }
                }
            } else {
                $msg = "Error: StatusNet authorization did not complete successfully. Check if your account already ".
                " exists. If not, please try again. Details: <pre>" . print_r($to, TRUE) . "</pre>";
                $this->addErrorMessage($msg);
            }
            $this->view_mgr->clear_all_cache();
        }
        return $this->generateView();
    }
}

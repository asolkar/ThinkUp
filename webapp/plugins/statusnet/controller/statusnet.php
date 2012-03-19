<?php
/*
 Plugin Name: StatusNet
 Plugin URI: http://github.com/asolkar/thinkup/tree/master/webapp/plugins/statusnet/
 Description: Capture and display notices.
 Icon: assets/img/statusnet_icon.png
 Class: StatusNetPlugin
 Version: 0.01
 Author: Mahesh Asolkar <mahesh[at]mahesha[dot]com>
 */

/**
 *
 * ThinkUp/webapp/plugins/statusnet/controller/statusnet.php
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
 */
/**
 * @author Gina Trapani <ginatrapani[at]gmail[dot]com>
 * @license http://www.gnu.org/licenses/gpl.html
 * @copyright 2009-2012 Gina Trapani
 */
$config = Config::getInstance();
//@TODO: For the testing sake, check if mock class has already been loaded
//@TODO: Figure out a better way to do this
if (!class_exists('StatusNetOAuth')) {
    Loader::addSpecialClass('StatusNetOAuth', 'plugins/statusnet/extlib/statusnetoauth/statusnetoauth.php');
}

$webapp = Webapp::getInstance();
$webapp->registerPlugin('statusnet', 'StatusNetPlugin');

$crawler = Crawler::getInstance();
$crawler->registerCrawlerPlugin('StatusNetPlugin');

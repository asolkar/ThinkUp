<?php
/**
 *
 * ThinkUp/webapp/plugins/statusnet/tests/TestOfStatusNetOAuth.php
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
require_once 'tests/init.tests.php';
require_once THINKUP_ROOT_PATH.'webapp/_lib/extlib/simpletest/autorun.php';
if (!class_exists('statusnetOAuth')) {
    require_once THINKUP_ROOT_PATH.'webapp/plugins/statusnet/tests/classes/mock.StatusNetOAuth.php';
}

class TestOfStatusNetOAuth extends UnitTestCase {

    public function testMakingAPICall() {
        $to = new StatusNetOAuth('', '', '', '');
        $result = $to->oAuthRequest('https://statusnet.com/users/show/anildash.xml', 'GET', array());
        $this->assertPattern('/Anil Dash/', $result);
    }
}
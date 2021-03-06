<?php
/**
 *
 * ThinkUp/tests/classes/class.TestFauxHookableApp.php
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
 *
 * Faux TestPluginHook class for TestOfPluginHook test
 *
 * @license http://www.gnu.org/licenses/gpl.html
 * @copyright 2009-2012 Gina Trapani
 * @author Gina Trapani <ginatrapani[at]gmail[dot]com>
 *
 */
class TestFauxHookableApp extends PluginHook {
    /**
     * For testing purposes
     */
    public function performAppFunction() {
        $this->emitObjectMethod('performAppFunction');
    }

    /**
     * For testing purposes
     * @param str $object_name Object name
     */
    public function registerPerformAppFunction($object_name) {
        $this->registerObjectMethod('performAppFunction', $object_name, 'performAppFunction');
    }
}

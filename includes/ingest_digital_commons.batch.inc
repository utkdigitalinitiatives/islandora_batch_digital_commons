<?php

/* 
 * Copyright (C) 2016 rwaltz
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

function islandora_batch_digital_commons_ingest_process(array $parameters, &$context) {
    module_load_include('inc', 'islandora_batch', 'includes/ingest.batch');
    islandora_batch_ingest_process($parameters, $context);
    
}
spl_autoload_register(function ($name) {
    if ((preg_match("/(DigitalCommonsScanBatch(Factory|Base|AWS|ZIP|DIR|Object))|(DigitalCommonsObjectInfo)|(DigitalCommonsFileInfo)/", $name )  ) === 1) {
      require_once (__DIR__ . "/$name" . ".php");
    }
});

<?php

namespace Rotary\Grants\Database;

defined( 'ABSPATH' ) || exit;

interface MigrationInterface {
    public function up(): void;
}

<?php

use App\Models\Tenant;

$t = Tenant::first();
if ($t) {
    echo 'public_id: '.$t->public_id.PHP_EOL;
    echo 'slug: '.$t->slug.PHP_EOL;
    echo 'tenancy_db_name: '.$t->tenancy_db_name.PHP_EOL;
    echo 'data: '.json_encode($t->data).PHP_EOL;
} else {
    echo 'no tenants found'.PHP_EOL;
}

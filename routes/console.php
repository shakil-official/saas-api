<?php

use App\Jobs\ExpireSubscriptions;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new ExpireSubscriptions)->hourly();

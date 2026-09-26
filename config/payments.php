<?php

return [
    'reservation_minutes' => max(1, (int) env('PAYMENT_RESERVATION_MINUTES', 30)),
];

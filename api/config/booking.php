<?php

return [
    /*
     | How far ahead of an appointment to send the customer's reminder.
     | The scheduler runs the reminder command frequently; each appointment is
     | reminded once (guarded by reminder_sent_at).
     */
    'reminder_lead_minutes' => (int) env('BOOKING_REMINDER_LEAD_MINUTES', 120),

    /*
     | Max active (upcoming, pending/confirmed) bookings a single customer may
     | hold per salon via the public booking page. To book again they must
     | cancel an existing one (or use another number). Admin walk-ins bypass
     | this. Set to 0 to disable the limit.
     */
    'max_active_per_customer' => (int) env('BOOKING_MAX_ACTIVE_PER_CUSTOMER', 1),
];

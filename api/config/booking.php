<?php

return [
    /*
     | How far ahead of an appointment to send the customer's reminder.
     | The scheduler runs the reminder command frequently; each appointment is
     | reminded once (guarded by reminder_sent_at).
     */
    'reminder_lead_minutes' => (int) env('BOOKING_REMINDER_LEAD_MINUTES', 120),
];

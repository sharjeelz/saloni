<?php

// Customer- and owner-facing SMS / WhatsApp copy. The salon's `locale` setting
// picks which file is used, so keep the keys identical across languages.
return [
    // Customer
    'confirmation' => ':salon: your :service with :staff is confirmed for :when. Ref :ref.',
    'reschedule'   => ':salon: your :service with :staff is now :when. Ref :ref.',
    'cancelled'    => ':salon: your :service on :when has been cancelled. Please contact us to rebook.',
    'reminder'     => 'Reminder — :salon: your :service with :staff is at :when.',

    // Owner
    'owner_new'        => 'New booking: :customer — :service with :staff on :when.',
    'owner_cancelled'  => 'Cancelled by customer: :customer — :service with :staff on :when.',
    'owner_reschedule' => 'Rescheduled by customer: :customer — :service with :staff, now :when.',
    'note'             => 'Note: :note',

    // Account
    'otp'          => 'Your verification code is :code. It expires in :minutes minutes.',
    'staff_invite' => "You've been added to :salon. Sign in with your phone number to get started.",
];

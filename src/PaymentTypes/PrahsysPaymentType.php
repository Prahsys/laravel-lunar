<?php

namespace Prahsys\Lunar\PaymentTypes;

class PrahsysPaymentType
{
    public function getId(): string
    {
        return 'prahsys';
    }

    public function getName(): string
    {
        return 'Prahsys Payments';
    }
}
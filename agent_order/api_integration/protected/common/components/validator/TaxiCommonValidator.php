<?php




class TaxiCommonValidator extends TaxiValidator {
    
    public function validate_createOrder($params) {
    
        
        if (empty($params['fromStreet']) && empty($params['fromHouse'])) {
            $this->addError('fromStreet', "Должны быть заполнены улица и(или) номер дома");
        }
        $this->ruleRequire($params, array(
            'phone' => 'Поле телефон должно быть заполнено',
        ))
        ;

        if ((integer) ($params['fromHouse']) > 10000) {
            $this->addError('fromHouse', "Номер дома заполнен не правильно");
        }

        if ((integer) ($params['toHouse']) > 10000) {
            $this->addError('toHouse', "Номер дома заполнен не правильно");
        }

        if (strlen($params['phone']) < 9) {
            $this->addError('phone', "Номер телефона заполнен не правильно");
        }

    }

    public function validate_createOrder_priorTime($value)
    {
        $time = $this->parseTime($value);
        if ($time && ($time < time() + 60)) {
            $this->addError('priorTime', "Дата и время {$priorTime} меньше текущей");
        }
    }

}

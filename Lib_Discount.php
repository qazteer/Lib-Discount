<?php if (!defined('DOCROOT')) exit('No access');

class Lib_Discount
{

    protected static $_instance;

    protected static $_id;
    protected static $_enable;
    protected static $_created_date;
    protected static $_date_start;
    protected static $_date_end;
    protected static $_bonus_for_condition;
    protected static $_name = [];
    protected static $_type = [];
    protected static $_value = [];
    protected static $_bonus_for = [];
    protected static $_expiration_time;
    protected static $_last_check;
    protected static $_api_timezone = [];

    ////////////////////////////////
    protected static $_id_vendor;
    protected static $_cnf_prefix;
    protected static $_discount_ids = [];
    protected static $_condition = [];

    static function instance()
    {
        if (NULL == self::$_instance)
        {
            self::$_instance = new static();
        }

        return self::$_instance;
    }

    function initialize(Model_Vendor $model_vendor)
    {
        static::$_id_vendor = $model_vendor->id;
        $cnf = Config::flyweight('settings');
        static::$_cnf_prefix = "site_{$model_vendor->config['site_id']}_";

        $applicable_discounts = (
            is_string($cnf->{static::$_cnf_prefix . "applicable_discounts"}) &&
            is_array(json_decode($cnf->{static::$_cnf_prefix . "applicable_discounts"})) &&
            (json_last_error() == JSON_ERROR_NONE)
        ) ? json_decode($cnf->{static::$_cnf_prefix . "applicable_discounts"}) : [];

        if (!empty($applicable_discounts)) {
            foreach ($applicable_discounts as $disc_id) {

                $new_cnf_prefix = static::$_cnf_prefix . $disc_id . "_";

                static::$_expiration_time = $cnf->{$new_cnf_prefix . "discount_expiration_time"};
                static::$_api_timezone[$disc_id] = $cnf->{$new_cnf_prefix . "discount_api_timezone"};
                static::$_last_check = $cnf->{$new_cnf_prefix . "discount_last_check"};

                /**
                 * If more 1 hour after last check,
                 * or expiration time is over
                 * need to update
                 */
                if (!(int)static::$_last_check
                    || static::$_last_check <= strtotime("-1 hour")) {

                    $this->update($model_vendor->config);

                } elseif ($this->_is_time_expired()
                    && static::$_last_check <= static::$_expiration_time) {

                    $this->update($model_vendor->config);
                }

                /** Need to check expiration time again after update */
                if (!$this->_is_time_expired()) {
                    static::$_name[$disc_id] = $cnf->{$new_cnf_prefix . "discount_name"};
                    static::$_type[$disc_id] = $cnf->{$new_cnf_prefix . "discount_type"};
                    static::$_value[$disc_id] = $cnf->{$new_cnf_prefix . "discount_value"};
                    static::$_bonus_for[$disc_id] = $cnf->{$new_cnf_prefix . "discount_bonus_for"};
                    static::$_condition[$disc_id] = $cnf->{$new_cnf_prefix . "discount_bonus_for_condition"};

                }
            }
        } else {

            $this->update($model_vendor->config);
        }
    }

    /**
     * @return bool
     */
    protected function _is_time_expired()
    {
        if ((int)static::$_expiration_time)
        {
            $expiration = \Carbon\Carbon::createFromTimestamp(static::$_expiration_time);
            $now = \Carbon\Carbon::now();

            return $expiration->lte($now); // less than now or equals
        }

        return false;
    }


    function update(array $_aff_cnf)
    {
        $result = with(new Lib_API_ORX())->get_discount($_aff_cnf);

        if ( ! $result)
        {
            return;
        }

        $result = json_decode($result, true);

        if (!isset($result[0]) || !is_array($result[0]))
        {
            return;
        }

        foreach ($result as $result_one) {
            $this->updateHelper($result_one);
        }

        $this->updateHelperDiscountIDS();
    }

    /**
     * Insert/Update discount data in options tables
     *
     * @param array $result
     */
    function updateHelper($result)
    {

        $cnf = Data::extract($result, array(
            'id',
            'enable',
            'created_date',
            'date_start',
            'date_end',
            'name' => 'title',
            'bonus_for',
            'bonus_for_condition',
            'type' => 'bonus_type',
            'value' => 'bonus_type_value',
            'expiration_time',
            'api_timezone' => 'timezone',
        ));

        if ( ! floatval($cnf['value']))
        {
            $cnf = array_keys($cnf);
            $cnf = array_fill_keys($cnf, null);
        }

        $cnf['last_check'] = time();
        array_push(static::$_discount_ids, $cnf['id']);

        $_cnf_prefix = static::$_cnf_prefix;
        $opt_names = array_keys($cnf);
        $opt_names = array_map(
            function ($input) use ($cnf, $_cnf_prefix) {return "{$_cnf_prefix}{$cnf['id']}_discount_{$input}";},
            $opt_names
        );

        $options = Model_Options::query()
            ->whereEntity('settings')
            ->whereIn('name', $opt_names)
            ->get()
            ->keyBy('name')
            ->toArray();

        $to_insert = array();

        foreach ($cnf as $key => $val)
        {
            $opt_name = "{$_cnf_prefix}{$cnf['id']}_discount_{$key}";
            $attr_name = "_$key";

            static::$$attr_name = $val;

            Config::flyweight('settings')->set($opt_name, $val);

            if (array_key_exists($opt_name, $options))
            {
                Model_Options::whereId($options[$opt_name]['id'])
                    ->update(array('value' => $val));
            }
            else
            {
                $to_insert[] = array(
                    'entity'    => 'settings',
                    'name'      => $opt_name,
                    'value'     => $val
                );
            }
        }

        /* Insert new values */
        if ($to_insert)
        {
            Model_Options::query()->insert($to_insert);
        }
    }

    /**
     * Insert/Update discount ids in options table
     */
    function updateHelperDiscountIDS()
    {
        $ids = json_encode(static::$_discount_ids);
        $_cnf_prefix = static::$_cnf_prefix;
        $opt_names = [
            "{$_cnf_prefix}discount_ids"
        ];

        $options = Model_Options::query()
            ->whereEntity('settings')
            ->whereIn('name', $opt_names)
            ->get()
            ->keyBy('name')
            ->toArray();

        $to_insert = array();

        foreach ($opt_names as $opt_name)
        {
            Config::flyweight('settings')->set($opt_name, $ids);

            if (array_key_exists($opt_name, $options))
            {
                Model_Options::whereId($options[$opt_name]['id'])
                    ->update(array('value' => $ids));
            }
            else
            {
                $to_insert[] = array(
                    'entity'    => 'settings',
                    'name'      => $opt_name,
                    'value'     => $ids
                );
            }
        }

        /* Insert new values */
        if ($to_insert)
        {
            Model_Options::query()->insert($to_insert);
        }
    }

    /**
     * @return array
     */
    static function type()
    {
        return static::$_type;
    }

    /**
     * @return array
     */
    static function bonus_for()
    {
        return static::$_bonus_for;
    }

    /**
     * @return array
     */
    static function condition()
    {
        return static::$_condition;
    }

    /**
     * @param int $disc_id
     * @return float
     */
    static function value($disc_id)
    {
        $value = 0;

        if (in_array(static::$_bonus_for[$disc_id], array(1, 2, 3)))
        {
            $value = static::$_value[$disc_id];
        }

        return floatval($value);
    }

    /**
     * @param float $price
     * @return array
     */
    static function discount($price)
    {
        $discounts = [];

        if (!is_array(static::type())) {
            return $discounts;
        }

        foreach (static::bonus_for() as $disc_id => $bonus_for) {
            switch ($bonus_for) {
                case 3:
                case 1:
                    $discounts[$bonus_for] = static::helper_discount_type($disc_id, $price);
                    break;
                case 2:
                    $arr_expression = explode("<=", static::$_condition[$disc_id]);
                    $arg_one = floatval(preg_replace("/[^-0-9\.]/","",$arr_expression[0]));
                    $arg_two = floatval(preg_replace("/[^-0-9\.]/","",$arr_expression[2]));
                    if ($arg_one <= $price && $price <= $arg_two) {
                        $discounts[$bonus_for] = static::helper_discount_type($disc_id, $price);
                    }
                    break;
            }
        }

        return $discounts;
    }

    static function helper_discount_type($disc_id, $price)
    {
        $type = static::type();

        if (empty($type[$disc_id])) {
            return floatval($price);
        }

        if ($type[$disc_id] == 7) {
            $discount = $price / 100 * static::value($disc_id);
        } else {
            $discount = static::value($disc_id);
        }

        return floatval($discount);
    }

    /**
     * @param float $price
     * @return float
     */
    static function discount_price($price)
    {
        $discounts = static::discount($price);

        if (empty($discounts)) {
            return $price;
        }

        foreach ($discounts as $discount) {
            if ($discount > 0
                && $price > $discount) {
                $price -= $discount;
            }
        }

        return $price;
    }

    /**
     * @return \Carbon\Carbon | null
     */
    static function expiration_time()
    {
        if ((int)static::$_expiration_time)
        {
            return \Carbon\Carbon::createFromTimestamp(static::$_expiration_time);
        }

        return null;
    }

    static function get_id_vendor()
    {
        return static::$_id_vendor;
    }

    static function clear_value($disc_id)
    {
        static::$_value[$disc_id] = 0;
    }
}

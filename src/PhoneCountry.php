<?php

declare(strict_types=1);

namespace Laravel\Jetstream;

class PhoneCountry
{
    /**
     * The supported phone countries as ISO 3166-1 alpha-2 => [name, dial code].
     *
     * @var array<string, array{name: string, dial: string}>
     */
    protected static array $countries = [
        'AF' => ['name' => 'Afghanistan', 'dial' => '93'],
        'AL' => ['name' => 'Albania', 'dial' => '355'],
        'DZ' => ['name' => 'Algeria', 'dial' => '213'],
        'AD' => ['name' => 'Andorra', 'dial' => '376'],
        'AO' => ['name' => 'Angola', 'dial' => '244'],
        'AR' => ['name' => 'Argentina', 'dial' => '54'],
        'AM' => ['name' => 'Armenia', 'dial' => '374'],
        'AU' => ['name' => 'Australia', 'dial' => '61'],
        'AT' => ['name' => 'Austria', 'dial' => '43'],
        'AZ' => ['name' => 'Azerbaijan', 'dial' => '994'],
        'BH' => ['name' => 'Bahrain', 'dial' => '973'],
        'BD' => ['name' => 'Bangladesh', 'dial' => '880'],
        'BY' => ['name' => 'Belarus', 'dial' => '375'],
        'BE' => ['name' => 'Belgium', 'dial' => '32'],
        'BZ' => ['name' => 'Belize', 'dial' => '501'],
        'BJ' => ['name' => 'Benin', 'dial' => '229'],
        'BT' => ['name' => 'Bhutan', 'dial' => '975'],
        'BO' => ['name' => 'Bolivia', 'dial' => '591'],
        'BA' => ['name' => 'Bosnia and Herzegovina', 'dial' => '387'],
        'BW' => ['name' => 'Botswana', 'dial' => '267'],
        'BR' => ['name' => 'Brazil', 'dial' => '55'],
        'BN' => ['name' => 'Brunei', 'dial' => '673'],
        'BG' => ['name' => 'Bulgaria', 'dial' => '359'],
        'BF' => ['name' => 'Burkina Faso', 'dial' => '226'],
        'BI' => ['name' => 'Burundi', 'dial' => '257'],
        'KH' => ['name' => 'Cambodia', 'dial' => '855'],
        'CM' => ['name' => 'Cameroon', 'dial' => '237'],
        'CA' => ['name' => 'Canada', 'dial' => '1'],
        'CV' => ['name' => 'Cape Verde', 'dial' => '238'],
        'CF' => ['name' => 'Central African Republic', 'dial' => '236'],
        'TD' => ['name' => 'Chad', 'dial' => '235'],
        'CL' => ['name' => 'Chile', 'dial' => '56'],
        'CN' => ['name' => 'China', 'dial' => '86'],
        'CO' => ['name' => 'Colombia', 'dial' => '57'],
        'KM' => ['name' => 'Comoros', 'dial' => '269'],
        'CG' => ['name' => 'Congo', 'dial' => '242'],
        'CD' => ['name' => 'Congo (DRC)', 'dial' => '243'],
        'CR' => ['name' => 'Costa Rica', 'dial' => '506'],
        'CI' => ['name' => "Côte d'Ivoire", 'dial' => '225'],
        'HR' => ['name' => 'Croatia', 'dial' => '385'],
        'CU' => ['name' => 'Cuba', 'dial' => '53'],
        'CY' => ['name' => 'Cyprus', 'dial' => '357'],
        'CZ' => ['name' => 'Czechia', 'dial' => '420'],
        'DK' => ['name' => 'Denmark', 'dial' => '45'],
        'DJ' => ['name' => 'Djibouti', 'dial' => '253'],
        'DO' => ['name' => 'Dominican Republic', 'dial' => '1'],
        'EC' => ['name' => 'Ecuador', 'dial' => '593'],
        'EG' => ['name' => 'Egypt', 'dial' => '20'],
        'SV' => ['name' => 'El Salvador', 'dial' => '503'],
        'GQ' => ['name' => 'Equatorial Guinea', 'dial' => '240'],
        'ER' => ['name' => 'Eritrea', 'dial' => '291'],
        'EE' => ['name' => 'Estonia', 'dial' => '372'],
        'SZ' => ['name' => 'Eswatini', 'dial' => '268'],
        'ET' => ['name' => 'Ethiopia', 'dial' => '251'],
        'FJ' => ['name' => 'Fiji', 'dial' => '679'],
        'FI' => ['name' => 'Finland', 'dial' => '358'],
        'FR' => ['name' => 'France', 'dial' => '33'],
        'GA' => ['name' => 'Gabon', 'dial' => '241'],
        'GM' => ['name' => 'Gambia', 'dial' => '220'],
        'GE' => ['name' => 'Georgia', 'dial' => '995'],
        'DE' => ['name' => 'Germany', 'dial' => '49'],
        'GH' => ['name' => 'Ghana', 'dial' => '233'],
        'GR' => ['name' => 'Greece', 'dial' => '30'],
        'GT' => ['name' => 'Guatemala', 'dial' => '502'],
        'GN' => ['name' => 'Guinea', 'dial' => '224'],
        'GW' => ['name' => 'Guinea-Bissau', 'dial' => '245'],
        'GY' => ['name' => 'Guyana', 'dial' => '592'],
        'HT' => ['name' => 'Haiti', 'dial' => '509'],
        'HN' => ['name' => 'Honduras', 'dial' => '504'],
        'HK' => ['name' => 'Hong Kong', 'dial' => '852'],
        'HU' => ['name' => 'Hungary', 'dial' => '36'],
        'IS' => ['name' => 'Iceland', 'dial' => '354'],
        'IN' => ['name' => 'India', 'dial' => '91'],
        'ID' => ['name' => 'Indonesia', 'dial' => '62'],
        'IR' => ['name' => 'Iran', 'dial' => '98'],
        'IQ' => ['name' => 'Iraq', 'dial' => '964'],
        'IE' => ['name' => 'Ireland', 'dial' => '353'],
        'IL' => ['name' => 'Israel', 'dial' => '972'],
        'IT' => ['name' => 'Italy', 'dial' => '39'],
        'JM' => ['name' => 'Jamaica', 'dial' => '1'],
        'JP' => ['name' => 'Japan', 'dial' => '81'],
        'JO' => ['name' => 'Jordan', 'dial' => '962'],
        'KZ' => ['name' => 'Kazakhstan', 'dial' => '7'],
        'KE' => ['name' => 'Kenya', 'dial' => '254'],
        'KW' => ['name' => 'Kuwait', 'dial' => '965'],
        'KG' => ['name' => 'Kyrgyzstan', 'dial' => '996'],
        'LA' => ['name' => 'Laos', 'dial' => '856'],
        'LV' => ['name' => 'Latvia', 'dial' => '371'],
        'LB' => ['name' => 'Lebanon', 'dial' => '961'],
        'LS' => ['name' => 'Lesotho', 'dial' => '266'],
        'LR' => ['name' => 'Liberia', 'dial' => '231'],
        'LY' => ['name' => 'Libya', 'dial' => '218'],
        'LI' => ['name' => 'Liechtenstein', 'dial' => '423'],
        'LT' => ['name' => 'Lithuania', 'dial' => '370'],
        'LU' => ['name' => 'Luxembourg', 'dial' => '352'],
        'MO' => ['name' => 'Macao', 'dial' => '853'],
        'MG' => ['name' => 'Madagascar', 'dial' => '261'],
        'MW' => ['name' => 'Malawi', 'dial' => '265'],
        'MY' => ['name' => 'Malaysia', 'dial' => '60'],
        'MV' => ['name' => 'Maldives', 'dial' => '960'],
        'ML' => ['name' => 'Mali', 'dial' => '223'],
        'MT' => ['name' => 'Malta', 'dial' => '356'],
        'MR' => ['name' => 'Mauritania', 'dial' => '222'],
        'MU' => ['name' => 'Mauritius', 'dial' => '230'],
        'MX' => ['name' => 'Mexico', 'dial' => '52'],
        'MD' => ['name' => 'Moldova', 'dial' => '373'],
        'MC' => ['name' => 'Monaco', 'dial' => '377'],
        'MN' => ['name' => 'Mongolia', 'dial' => '976'],
        'ME' => ['name' => 'Montenegro', 'dial' => '382'],
        'MA' => ['name' => 'Morocco', 'dial' => '212'],
        'MZ' => ['name' => 'Mozambique', 'dial' => '258'],
        'MM' => ['name' => 'Myanmar', 'dial' => '95'],
        'NA' => ['name' => 'Namibia', 'dial' => '264'],
        'NP' => ['name' => 'Nepal', 'dial' => '977'],
        'NL' => ['name' => 'Netherlands', 'dial' => '31'],
        'NZ' => ['name' => 'New Zealand', 'dial' => '64'],
        'NI' => ['name' => 'Nicaragua', 'dial' => '505'],
        'NE' => ['name' => 'Niger', 'dial' => '227'],
        'NG' => ['name' => 'Nigeria', 'dial' => '234'],
        'KP' => ['name' => 'North Korea', 'dial' => '850'],
        'MK' => ['name' => 'North Macedonia', 'dial' => '389'],
        'NO' => ['name' => 'Norway', 'dial' => '47'],
        'OM' => ['name' => 'Oman', 'dial' => '968'],
        'PK' => ['name' => 'Pakistan', 'dial' => '92'],
        'PS' => ['name' => 'Palestine', 'dial' => '970'],
        'PA' => ['name' => 'Panama', 'dial' => '507'],
        'PG' => ['name' => 'Papua New Guinea', 'dial' => '675'],
        'PY' => ['name' => 'Paraguay', 'dial' => '595'],
        'PE' => ['name' => 'Peru', 'dial' => '51'],
        'PH' => ['name' => 'Philippines', 'dial' => '63'],
        'PL' => ['name' => 'Poland', 'dial' => '48'],
        'PT' => ['name' => 'Portugal', 'dial' => '351'],
        'PR' => ['name' => 'Puerto Rico', 'dial' => '1'],
        'QA' => ['name' => 'Qatar', 'dial' => '974'],
        'RO' => ['name' => 'Romania', 'dial' => '40'],
        'RU' => ['name' => 'Russia', 'dial' => '7'],
        'RW' => ['name' => 'Rwanda', 'dial' => '250'],
        'SA' => ['name' => 'Saudi Arabia', 'dial' => '966'],
        'SN' => ['name' => 'Senegal', 'dial' => '221'],
        'RS' => ['name' => 'Serbia', 'dial' => '381'],
        'SL' => ['name' => 'Sierra Leone', 'dial' => '232'],
        'SG' => ['name' => 'Singapore', 'dial' => '65'],
        'SK' => ['name' => 'Slovakia', 'dial' => '421'],
        'SI' => ['name' => 'Slovenia', 'dial' => '386'],
        'SO' => ['name' => 'Somalia', 'dial' => '252'],
        'ZA' => ['name' => 'South Africa', 'dial' => '27'],
        'KR' => ['name' => 'South Korea', 'dial' => '82'],
        'SS' => ['name' => 'South Sudan', 'dial' => '211'],
        'ES' => ['name' => 'Spain', 'dial' => '34'],
        'LK' => ['name' => 'Sri Lanka', 'dial' => '94'],
        'SD' => ['name' => 'Sudan', 'dial' => '249'],
        'SR' => ['name' => 'Suriname', 'dial' => '597'],
        'SE' => ['name' => 'Sweden', 'dial' => '46'],
        'CH' => ['name' => 'Switzerland', 'dial' => '41'],
        'SY' => ['name' => 'Syria', 'dial' => '963'],
        'TW' => ['name' => 'Taiwan', 'dial' => '886'],
        'TJ' => ['name' => 'Tajikistan', 'dial' => '992'],
        'TZ' => ['name' => 'Tanzania', 'dial' => '255'],
        'TH' => ['name' => 'Thailand', 'dial' => '66'],
        'TL' => ['name' => 'Timor-Leste', 'dial' => '670'],
        'TG' => ['name' => 'Togo', 'dial' => '228'],
        'TT' => ['name' => 'Trinidad and Tobago', 'dial' => '1'],
        'TN' => ['name' => 'Tunisia', 'dial' => '216'],
        'TR' => ['name' => 'Türkiye', 'dial' => '90'],
        'TM' => ['name' => 'Turkmenistan', 'dial' => '993'],
        'UG' => ['name' => 'Uganda', 'dial' => '256'],
        'UA' => ['name' => 'Ukraine', 'dial' => '380'],
        'AE' => ['name' => 'United Arab Emirates', 'dial' => '971'],
        'GB' => ['name' => 'United Kingdom', 'dial' => '44'],
        'US' => ['name' => 'United States', 'dial' => '1'],
        'UY' => ['name' => 'Uruguay', 'dial' => '598'],
        'UZ' => ['name' => 'Uzbekistan', 'dial' => '998'],
        'VE' => ['name' => 'Venezuela', 'dial' => '58'],
        'VN' => ['name' => 'Vietnam', 'dial' => '84'],
        'YE' => ['name' => 'Yemen', 'dial' => '967'],
        'ZM' => ['name' => 'Zambia', 'dial' => '260'],
        'ZW' => ['name' => 'Zimbabwe', 'dial' => '263'],
    ];

    /**
     * Get all of the supported phone countries.
     *
     * @return array<string, array{name: string, dial: string}>
     */
    public static function all(): array
    {
        return static::$countries;
    }

    /**
     * Determine if the given ISO 3166-1 alpha-2 code is supported.
     */
    public static function isValid(string $iso): bool
    {
        return array_key_exists($iso, static::$countries);
    }

    /**
     * Get the international dialing code for the given country.
     */
    public static function dialCode(string $iso): ?string
    {
        return static::$countries[$iso]['dial'] ?? null;
    }

    /**
     * Format the given national number as an E.164 phone number.
     *
     * Returns null when the country is unknown or the number is not a
     * plausible national number.
     */
    public static function toE164(string $iso, string $nationalNumber): ?string
    {
        $dial = static::dialCode($iso);

        if ($dial === null) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $nationalNumber) ?? '';

        $digits = ltrim($digits, '0');

        if (strlen($digits) < 4 || strlen($dial.$digits) > 15) {
            return null;
        }

        return '+'.$dial.$digits;
    }
}

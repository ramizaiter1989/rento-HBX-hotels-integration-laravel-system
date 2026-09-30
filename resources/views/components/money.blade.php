@props(['amount' => null, 'currency' => null])
<span>{{ \App\Support\DecimalString::display(is_numeric($amount) && ! is_string($amount) ? (string) $amount : $amount, $currency) }}</span>

<?php

namespace Tests\Support;

final class HbxFixture
{
    public static function availability(string $rateKey, string $rateType = 'BOOKABLE'): string
    {
        return json_encode([
            'auditData' => ['processTime' => '20', 'timestamp' => '2026-09-27 12:00:00.000'],
            'hotels' => [
                'checkIn' => '2026-10-10',
                'checkOut' => '2026-10-11',
                'total' => 1,
                'hotels' => [[
                    'code' => 712,
                    'name' => 'Test Hotel',
                    'categoryName' => '4 STARS',
                    'destinationCode' => 'PMI',
                    'destinationName' => 'Majorca',
                    'zoneName' => "Cala d'Or",
                    'latitude' => '39.370',
                    'longitude' => '3.230',
                    'minRate' => '121.18',
                    'maxRate' => '140.00',
                    'currency' => 'EUR',
                    'rooms' => [[
                        'code' => 'SUI.ST',
                        'name' => '1 BEDROOM 2 ADULTS',
                        'rates' => [[
                            'rateKey' => $rateKey,
                            'rateClass' => 'NOR',
                            'rateType' => $rateType,
                            'net' => '121.18',
                            'allotment' => 41,
                            'paymentType' => 'AT_WEB',
                            'packaging' => false,
                            'boardCode' => 'BB',
                            'boardName' => 'BED AND BREAKFAST',
                            'cancellationPolicies' => [[
                                'amount' => '121.18',
                                'from' => '2026-10-08T23:59:00+02:00',
                            ]],
                            'taxes' => [
                                'allIncluded' => false,
                                'taxes' => [[
                                    'included' => false,
                                    'amount' => '4.40',
                                    'currency' => 'EUR',
                                    'subType' => 'City Tax',
                                    'clientAmount' => '4.40',
                                    'clientCurrency' => 'EUR',
                                ]],
                            ],
                            'rateComments' => 'Tax payable on arrival.',
                        ]],
                    ]],
                ]],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    public static function checkRate(string $rateKey, string $net = '121.18'): string
    {
        return json_encode([
            'auditData' => ['processTime' => '15', 'timestamp' => '2026-09-27 12:05:00.000'],
            'hotel' => [
                'code' => 712,
                'name' => 'Test Hotel',
                'currency' => 'EUR',
                'paymentDataRequired' => false,
                'rooms' => [[
                    'code' => 'SUI.ST',
                    'name' => '1 BEDROOM 2 ADULTS',
                    'rates' => [[
                        'rateKey' => $rateKey,
                        'rateClass' => 'NOR',
                        'rateType' => 'BOOKABLE',
                        'net' => $net,
                        'allotment' => 40,
                        'paymentType' => 'AT_WEB',
                        'packaging' => false,
                        'boardCode' => 'BB',
                        'boardName' => 'BED AND BREAKFAST',
                        'cancellationPolicies' => [[
                            'amount' => '121.18',
                            'from' => '2026-10-08T23:59:00+02:00',
                        ]],
                        'taxes' => [
                            'taxes' => [[
                                'included' => false,
                                'amount' => '4.40',
                                'currency' => 'EUR',
                                'subType' => 'City Tax',
                            ]],
                        ],
                        'rateComments' => 'Tax payable on arrival.',
                    ]],
                ]],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    public static function booking(string $reference = '1-9000001'): string
    {
        return json_encode([
            'auditData' => ['processTime' => '80', 'timestamp' => '2026-09-27 12:10:00.000'],
            'booking' => [
                'reference' => $reference,
                'clientReference' => 'REPLACED',
                'creationDate' => '2026-09-27',
                'status' => 'CONFIRMED',
                'modificationPolicies' => ['cancellation' => true, 'modification' => true],
                'holder' => ['name' => 'Booking', 'surname' => 'Test'],
                'hotel' => [
                    'checkIn' => '2026-10-10',
                    'checkOut' => '2026-10-11',
                    'code' => 712,
                    'name' => 'Test Hotel',
                    'destinationName' => 'Majorca',
                    'zoneName' => "Cala d'Or",
                    'totalNet' => '121.18',
                    'currency' => 'EUR',
                    'supplier' => ['name' => 'HOTELBEDS', 'vatNumber' => 'ESB000'],
                    'rooms' => [[
                        'status' => 'CONFIRMED',
                        'id' => 1,
                        'code' => 'SUI.ST',
                        'name' => '1 BEDROOM 2 ADULTS',
                        'paxes' => [
                            ['roomId' => 1, 'type' => 'AD', 'name' => 'First', 'surname' => 'Guest'],
                            ['roomId' => 1, 'type' => 'AD', 'name' => 'Second', 'surname' => 'Guest'],
                        ],
                        'rates' => [[
                            'rateClass' => 'NOR',
                            'net' => '121.18',
                            'paymentType' => 'AT_WEB',
                            'packaging' => false,
                            'boardCode' => 'BB',
                            'boardName' => 'BED AND BREAKFAST',
                            'cancellationPolicies' => [[
                                'amount' => '118.13',
                                'from' => '2026-10-08T23:59:00+02:00',
                            ]],
                            'taxes' => [
                                'taxes' => [[
                                    'included' => false,
                                    'amount' => '4.40',
                                    'currency' => 'EUR',
                                    'subType' => 'City Tax',
                                ]],
                            ],
                            'rateComments' => 'Tax payable on arrival.',
                        ]],
                    ]],
                ],
                'remark' => 'Rento HBX local booking test',
                'totalNet' => '121.18',
                'pendingAmount' => '121.18',
                'currency' => 'EUR',
            ],
        ], JSON_THROW_ON_ERROR);
    }

    public static function detail(string $reference, string $status = 'CONFIRMED', string $surname = 'Test'): string
    {
        $data = json_decode(self::booking($reference), true, 512, JSON_THROW_ON_ERROR);
        $data['booking']['status'] = $status;
        $data['booking']['holder']['surname'] = $surname;

        return json_encode($data, JSON_THROW_ON_ERROR);
    }
}

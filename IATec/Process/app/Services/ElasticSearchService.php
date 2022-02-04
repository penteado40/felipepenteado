<?php

declare(strict_types=1);

namespace App\Services;

class ElasticSearchService
{
    private function http(string $urlService, string $path, string $method = "GET", array $payload = null)
    {
        $curl = curl_init();

        $confs = array(
            CURLOPT_URL => "{$urlService}{$path}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_AUTOREFERER => true,
            CURLOPT_VERBOSE => 0,
            CURLOPT_ENCODING => "",
            CURLOPT_TIMEOUT => 30000,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
            ),
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            // CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:80.0) Gecko/20100101 Firefox/80.0",
        );

        if ($method == 'POST') {
            $confs[CURLOPT_CUSTOMREQUEST] = "POST";
        }
        if (is_array($payload)) {
            $confs[CURLOPT_POSTFIELDS] = json_encode($payload);
        }

        curl_setopt_array($curl, $confs);
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            #echo "cURL Error #:" . $err;
            return null;
        }
        return json_decode($response, true);
    }

    private function search(string $urlService, string $beginDate, string $endDate)
    {
        $payload = [
            "query" => [
                "range" => [
                    "timestamp" => [
                        "gte" => "$beginDate 00:00:00.000",
                        "lt" => "$endDate 23:59:59.999",
                    ],
                ],
            ],
            "size" => 0,
            "aggs" => [
                "result" => [
                    "composite" => [
                        "sources" => [
                            [
                                "host" => [
                                    "terms" => [
                                        "field" => "host",
                                        #"missing_bucket" => true,
                                    ],
                                ],
                            ],
                        ],
                        "size" => 100000,
                    ],
                    "aggs" => [
                        "upstream_addr" => [
                            "terms" => [
                                "field" => "upstream_addr",
                            ],
                            "aggs" => [
                                "request_time_sum" => [
                                    "sum" => [
                                        "field" => "request_time"
                                    ],
                                ],
                                "request_length_sum" => [
                                    "sum" => [
                                        "field" => "request_length"
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $data = $this->http($urlService, "/_search", "GET", $payload);

        if (is_null($data)) {
            return [];
        }

        $formatted = array();
        foreach ($data['aggregations']['result']['buckets'] as $host) {
            $host_ids = array();
            foreach ($host['upstream_addr']['buckets'] as $upstream_addr)
            {
                $split_upstream_addr = explode(', ', $upstream_addr['key']);
                $fixed_upstream_addr = explode(':', end($split_upstream_addr))[0];

                # Ignore upstream addresses that aren't an ip
                if (ctype_alpha($fixed_upstream_addr[0])) {
                    continue;
                }

                if (isset($host_ids[$fixed_upstream_addr])) {
                    $key = $host_ids[$fixed_upstream_addr];
                    $formatted[$key]['length'] += $upstream_addr['request_length_sum']['value'];
                    $formatted[$key]['time'] += $upstream_addr['request_time_sum']['value'];
                    $formatted[$key]['count'] += $upstream_addr['doc_count'];
                } else {
                    array_push($formatted, [
                        'host' => $host['key']['host'],
                        'upstream_addr' => $fixed_upstream_addr,
                        'length' => $upstream_addr['request_length_sum']['value'],
                        'time' => $upstream_addr['request_time_sum']['value'],
                        'count' => $upstream_addr['doc_count'],
                    ]);
                    $host_ids[$fixed_upstream_addr] = count($formatted) - 1;
                }
            }
        }

        return $formatted;
    }

    public function searchIATec(string $beginDate, string $endDate){
        return $this->search(env("ELASTICSEARCH_IATEC"), $beginDate, $endDate);
    }

    public function searchSoftlayer(string $beginDate, string $endDate){
        return $this->search(env("ELASTICSEARCH_SOFTLAYER"), $beginDate, $endDate);
    }
}

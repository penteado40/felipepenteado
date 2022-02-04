<?php

declare(strict_types=1);

namespace App\Services;

class AzureDevOpsService
{
    const URL = "https://dev.azure.com/";
    const VERSION = "6.0";

    private $project = null;

    private function http(string $path, array $queryParams = [], string $method = "GET", array $payload = null)
    {
        $curl = curl_init();
        $queryParams['api-version'] = self::VERSION;

        $confs = array(
            CURLOPT_URL => self::URL."{$this->organization}{$path}?" . http_build_query($queryParams),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_AUTOREFERER => true,
            CURLOPT_VERBOSE => 0,
            CURLOPT_ENCODING => "",
            CURLOPT_TIMEOUT => 30000,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array(
                'Authorization: Basic '. base64_encode(":".config('services.azure_devops.key')),
                'Content-Type: application/json',
            ),
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            // CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:80.0) Gecko/20100101 Firefox/80.0",
        );

        if ($method == 'POST') {
            $confs[CURLOPT_CUSTOMREQUEST] = "POST";
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

    public function __construct(string $organization = 'sda-iatec')
    {
        $this->organization = "$organization/";
    }

    public function setProject(string $project)
    {
        if (!empty($project)) {
            $this->project = "$project/";
        }
    }

    public function getAllProjects()
    {
        $data = $this->http('_apis/projects', ['$top' => 10000]);

        $formated = array_map(function($row){
            return array(
                "id" => $row['id'],
                "name" => isset($row['name']) ? $row['name'] : '',
                "description" => isset($row['description']) ? $row['description'] : '',
                "url" => isset($row['url']) ? $row['url'] : '',
                "state" => isset($row['state']) ? $row['state'] : '',
                "revision" => isset($row['revision']) ? $row['revision'] : '',
                "visibility" => isset($row['visibility']) ? $row['visibility'] : '',
                "lastUpdateTime" => isset($row['lastUpdateTime']) ? $row['lastUpdateTime'] : '',
            );
        }, $data['value']);
        $data['value'] = $formated;

        return $data;
    }

    public function getWorkItems(string $beginDate, string $endDate, ?string $project = null)
    {
        $project = $project ?? $this->project;
        if (!empty($project)) {
            $project = "$project/";
        }

        $payload = array(
            "query" => "Select [System.Id] From WorkItems Where  [State] = 'Closed' AND [State] <> 'Removed' AND [AgileIATec.IATecClosedDate] > '$beginDate' AND [AgileIATec.IATecClosedDate] < '$endDate'"
        );
        $data = $this->http("{$project}_apis/wit/wiql", [], "POST", $payload);
        return $data;
    }

    public function getAllWorkItemsDescription(string $beginDate, string $endDate, ?string $project = null)
    {
        $workitems = $this->getWorkItems($beginDate, $endDate, $project);
        $workitems = array_map(function ($row) {
            return $row["id"];
        }, $workitems['workItems']);

        $wi = [];

        $chunkeds = array_chunk($workitems, 200);
        foreach ($chunkeds as $chunked) {
            $ids = join(",", $chunked);
            $items = $this->http("_apis/wit/workitems", [
                'ids' => $ids,
                // '$expand' => 'fields',
                'fields' => 'System.Id,System.TeamProject,System.AreaPath,System.Title,System.AssignedTo,System.CreatedDate,System.State,System.WorkItemType,System.Parent,Microsoft.VSTS.Scheduling.RemainingWork,Microsoft.VSTS.Scheduling.OriginalEstimate,Microsoft.VSTS.Scheduling.CompletedWork,Microsoft.VSTS.Common.ClosedDate,AgileIATec.IATecClosedDate,System.ChangedDate',
                '$expand' => 'links'
            ]);

            $formated = array_map(function($row){
                $url = preg_match("#/{$this->organization}([a-z0-9-]+)#", $row['_links']['self']['href'], $match);
                $project_id = null;
                if($url){
                    $project_id = $match[1];
                }
                return array(
                    "work_item_id" => $row["fields"]["System.Id"],
                    "project_external_id" => $project_id,
                    // "projeto" => $row['fields']['System.TeamProject'],
                    "parent_id" => isset($row['fields']['System.Parent']) ? $row['fields']['System.Parent'] : null,
                    "type" => $row['fields']['System.WorkItemType'],
                    "user" => isset($row['fields']['System.AssignedTo']) ? $row['fields']['System.AssignedTo']['uniqueName'] : null,
                    "title" => $row['fields']['System.Title'],
                    "area" => $row['fields']['System.TeamProject'],
                    "board" => $row['fields']['System.AreaPath'],
                    "status" => $row['fields']['System.State'],
                    "remaining_work" => isset($row['fields']['Microsoft.VSTS.Scheduling.RemainingWork']) ? $row['fields']['Microsoft.VSTS.Scheduling.RemainingWork'] : null,
                    "original_estimate" => isset($row['fields']['Microsoft.VSTS.Scheduling.OriginalEstimate']) ? $row['fields']['Microsoft.VSTS.Scheduling.OriginalEstimate'] : null,
                    "hours" => isset($row['fields']['Microsoft.VSTS.Scheduling.CompletedWork']) ? $row['fields']['Microsoft.VSTS.Scheduling.CompletedWork'] : null,
                    "created_date" => isset($row['fields']['System.CreatedDate']) ? $row['fields']['System.CreatedDate'] : null,
                    "changed_date" => isset($row['fields']['System.ChangedDate']) ? $row['fields']['System.ChangedDate'] : null,
                    "closed_date" => isset($row['fields']['Microsoft.VSTS.Common.ClosedDate']) ? $row['fields']['Microsoft.VSTS.Common.ClosedDate'] : null,
                    "iatec_closed_date" => isset($row['fields']['AgileIATec.IATecClosedDate']) ? $row['fields']['AgileIATec.IATecClosedDate'] : null,
                );
            }, $items['value'] ?? []);
            array_push($wi, ...$formated);
        }

        return $wi;
    }
}

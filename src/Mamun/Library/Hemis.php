<?php

namespace App\Library;

use App\Library\Mamun;

class Hemis 
{

    const hemis_key = 'cbdfefbb283db3a219a7e7dcefd620b4';

    public function getHemisData($target, $param = null) {
        switch ($target) {
            case 'employee_list':
                if (isset($param['search'])) {
                    if (isset($param['all'])) {
                        $limit = 200;
                    } else {
                        $limit = 1;
                    }
                    if (isset($param['type'])) {
                        $items = $this->getHemisItems('employee-list?limit='. $limit .'&type='. $param['type'] .'&search=' . $param['search']);
                    } else {
                        $items = $this->getHemisItems('employee-list?limit='. $limit .'&type=all&search=' . $param['search']);
                    }
                    if ($items) {
                        if ($limit == 1) {
                            return $items[0];
                        } else {
                            return $items;                            
                        }
                    }
                }   
                break;
            case 'classifier_list':
                if (isset($param['classifier'])) {
                    $items = $this->getHemisItems('classifier-list?limit=1&classifier=' . $param['classifier']);
                    if ($items) {
                        $result = null;
                        foreach ($items[0]->options as $item) {
                            $result[] = $item->name;
                        }
                        return $result;
                    }
                }
                break;
            case 'department_list':
                if (isset($param['type'])) {
                    if ($param['type'] == 'Ishchi') $type = "employee"; else $type = "teacher";
                    $items = $this->getHemisItems('employee-list?limit=200&type=' . $type);                    
                    if ($items) {
                        $result = [];
                        foreach ($items as $item) {
                            $name = trim($item->department->name);
                            if (!in_array($name, $result)) $result[] = $name;
                        }
                        return $result;
                    }
                }
                break;
            case 'student_list':
                return $this->getHemisItems('student-list?limit=200');
                break;
            case 'worker_list':
                return $this->getHemisItems('employee-list?limit=200&type=all');
                break;
            case 'schedule_list':
                return $this->getHemisItems('schedule-list?limit=200&lesson_date_from='. $param['from_date'] .'&lesson_date_to='. $param['to_date']);
                break;
            case 'tutor_groups':
                if (isset($param['search'])) {
                    $items = $this->getHemisItems('employee-list?limit=1&type=employee&search=' . $param['search']);
                    if (!$items) {
                        $items = $this->getHemisItems('employee-list?limit=1&type=teacher&search=' . $param['search']);
                    }
                    if ($items) {
                        return $items[0]->tutorGroups;
                    }
                }   
                break;
            case 'student_info':
                if (isset($param['id'])) {
                    $datas = $this->getHemisDatas('student-info?student_id_number=' . $param['id']);
                    if (isset($datas->id)) {
                        return $datas;
                    }
                }
                break;
        }
        return null;
    }

    private function getHemisItems($command) {
        $result = null;
        // info('start');
        $data = Mamun::Http()->getRequest('https://student.mamunedu.uz/rest/v1/data/' . $command, null, self::hemis_key, 'GET');
        if ($data) {
            $data = json_decode($data);
            if ($data and $data->success and (count($data->data->items) > 0)) {
                $page_count = $data->data->pagination->pageCount;
                $total_count = $data->data->pagination->totalCount;
                // info($total_count .'/'. $page_count);
                $result = $data->data->items;
                if ($page_count > 1) {
                    // info('HEMIS/'. $command .': 1/'. count($result));
                    $page = 2;
                    do {
                        sleep(10);
                        $data = Mamun::Http()->getRequest('https://student.mamunedu.uz/rest/v1/data/' . $command . '&page=' . $page, null, self::hemis_key, 'GET');
                        if ($data) {
                            $data = json_decode($data);
                            if ($data and $data->success and (count($data->data->items) > 0)) {
                                $result = array_merge($result, $data->data->items);
                                $page++;
                            }
                        }
                        // info('HEMIS/'. $command .': '. $page .'/'. count($result));
                    } while ($page <= $page_count);

                    // for ($page = 2; $page <= $page_count; $page++) {
                    //     sleep(10);
                    //     $data = Mamun::Http()->getRequest('https://student.mamunedu.uz/rest/v1/data/' . $command . '&page=' . $page, null, self::hemis_key, 'GET');
                    //     if ($data) {
                    //         $data = json_decode($data);
                    //         if ($data and $data->success and (count($data->data->items) > 0)) {
                    //             $result = array_merge($result, $data->data->items);
                    //         }
                    //     }
                    //     // info('HEMIS/'. $command .': '. $page .'/'. count($result));
                    // }
                }
                // if ($total_count <> count($result)) {
                //     $result = null;
                // }
            }
        }
        if ($result) {
            info('HEMIS/'. $command .': '. count($result));
        }
        return $result;
    }

    private function getHemisDatas($command) {
        $result = null;
        $data = Mamun::Http()->getRequest('https://student.mamunedu.uz/rest/v1/data/' . $command, null, self::hemis_key, 'GET');
        if (isset($data)) {
            $data = json_decode($data);
            if ($data and $data->success) {
                $result = $data->data;
            }
        }
        return $result;
    }
            
}
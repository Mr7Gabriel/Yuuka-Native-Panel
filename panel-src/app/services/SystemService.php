<?php
declare(strict_types=1);

final class SystemService
{
    public static function summary(): array
    {
        return [
            'cpu_percent' => sys_cpu_usage_percent(),
            'ram' => sys_ram_usage(),
            'disk' => sys_disk_usage(),
            'load' => sys_load_average(),
            'uptime' => sys_uptime_human(),
            'uptime_seconds' => sys_uptime_seconds(),
        ];
    }

    /** @return array<string,string> serviceName => active|inactive|failed|unknown */
    public static function serviceStatuses(): array
    {
        $services = ['nginx', 'mariadb', 'cloudflared'];
        foreach (PhpService::installedVersions() as $v) {
            $services[] = "php{$v}-fpm";
        }

        $statuses = [];
        foreach ($services as $svc) {
            $statuses[$svc] = sys_service_status($svc);
        }
        return $statuses;
    }

    public static function nodejsRunningCount(): int
    {
        $count = 0;
        foreach (nodejs_pm2_jlist() as $proc) {
            if ($proc['status'] === 'online') {
                $count++;
            }
        }
        return $count;
    }
}

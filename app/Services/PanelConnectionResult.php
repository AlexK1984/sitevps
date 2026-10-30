<?php

namespace App\Services;

class PanelConnectionResult
{
    public function __construct(
        public readonly bool $online,
        public readonly string $diagnostic,
    ) {}

    public function message(): string
    {
        return match ($this->diagnostic) {
            'ok' => 'Панель доступна: вход и read-only запрос списка inbound успешны.',
            'dns_error' => 'Не удалось разрешить DNS-имя панели. Проверьте DNS с сервера сайта.',
            'connect_timeout' => 'Истекло время ожидания ответа панели. Проверьте доступность и firewall.',
            'tls_error' => 'Ошибка TLS-сертификата панели. Проверьте сертификат и имя хоста; не отключайте проверку TLS.',
            'connection_refused' => 'Панель отклонила TCP-подключение. Проверьте порт и firewall.',
            'network_error' => 'Сетевая ошибка при подключении к панели. Проверьте маршрут и доступность порта.',
            'auth_failed' => 'Панель отклонила учётные данные или сессию. Проверьте логин и пароль.',
            'login_endpoint_not_found' => 'Не найден endpoint входа 3x-ui. Проверьте base URL, включая reverse-proxy path и совместимость версии.',
            'api_endpoint_not_found' => 'Не найден API endpoint списка inbound. Проверьте base URL, reverse-proxy path и версию 3x-ui.',
            'unsupported_api' => 'Ответ панели не соответствует ожидаемому API 3x-ui. Проверьте версию и reverse-proxy path.',
            'server_error' => 'Панель вернула внутреннюю HTTP-ошибку. Проверьте состояние панели и её reverse proxy.',
            'http_error' => 'Панель вернула неожиданный HTTP-статус. Проверьте настройки доступа и reverse proxy.',
            'inbound_not_found' => 'Вход в панель выполнен, но настроенный inbound ID не найден.',
            default => 'Не удалось определить причину ответа панели.',
        };
    }
}

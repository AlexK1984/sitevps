<?php

namespace App\Services;

class PanelConnectionResult
{
    public function __construct(
        public readonly bool $online,
        public readonly string $diagnostic,
        public readonly ?int $httpStatus = null,
    ) {}

    public function message(): string
    {
        $message = match ($this->diagnostic) {
            'ok' => 'Панель доступна: вход и read-only запрос списка inbound успешны.',
            'dns_error' => 'Не удалось разрешить DNS-имя панели. Проверьте DNS с сервера сайта.',
            'connect_timeout' => 'Истекло время ожидания ответа панели. Проверьте доступность и firewall.',
            'tls_error' => 'Ошибка TLS-сертификата панели. Проверьте сертификат и имя хоста; не отключайте проверку TLS.',
            'connection_refused' => 'Панель отклонила TCP-подключение. Проверьте порт и firewall.',
            'network_error' => 'Сетевая ошибка при подключении к панели. Проверьте маршрут и доступность порта.',
            'login_rejected' => '3x-ui вернула success=false для входа (возможны учётные данные, 2FA или ограничение входа).',
            'login_unauthorized' => 'Endpoint входа вернул HTTP 401. Панель отклонила запрос входа; проверьте настройки входа и 2FA.',
            'login_forbidden' => 'Endpoint входа вернул HTTP 403. Доступ к входу запрещён политикой панели или reverse proxy.',
            'login_redirect' => 'Endpoint входа перенаправил запрос вместо JSON-ответа. Проверьте base URL и reverse-proxy path.',
            'login_html' => 'Endpoint входа вернул HTML-страницу вместо JSON API (возможно, неверен base URL или reverse-proxy path).',
            'login_endpoint_not_found' => 'Не найден endpoint входа 3x-ui. Проверьте base URL, включая reverse-proxy path и совместимость версии.',
            'api_unauthorized' => 'API списка inbound вернул HTTP 401: панель не приняла сессию входа.',
            'api_forbidden' => 'API списка inbound вернул HTTP 403: доступ запрещён политикой панели.',
            'api_redirect' => 'API списка inbound перенаправил запрос (возможно, на страницу входа). Проверьте base URL и cookie path.',
            'api_html' => 'API списка inbound вернул HTML вместо JSON (возможно, сессию перенаправило на страницу входа).',
            'api_endpoint_not_found' => 'Не найден API endpoint списка inbound. Проверьте base URL, reverse-proxy path и версию 3x-ui.',
            'login_malformed' => 'Endpoint входа ответил, но JSON не соответствует формату 3x-ui.',
            'api_malformed' => 'API ответил, но JSON не соответствует формату 3x-ui.',
            'server_error' => 'Панель вернула внутреннюю HTTP-ошибку. Проверьте состояние панели и её reverse proxy.',
            'http_error' => 'Панель вернула неожиданный HTTP-статус. Проверьте настройки доступа и reverse proxy.',
            'inbound_not_found' => 'Вход в панель выполнен, но настроенный inbound ID не найден.',
            'csrf_redirect' => 'Endpoint CSRF перенаправил запрос. Проверьте base URL и reverse-proxy path.',
            'csrf_unauthorized' => 'Endpoint CSRF вернул HTTP 401. Проверьте доступ к панели и base URL.',
            'csrf_forbidden' => 'Endpoint CSRF вернул HTTP 403. Проверьте правила доступа reverse proxy.',
            'csrf_html' => 'Endpoint CSRF вернул HTML вместо JSON. Проверьте base URL и reverse-proxy path.',
            'csrf_malformed' => 'Endpoint CSRF ответил, но JSON не содержит ожидаемый токен.',
            default => 'Не удалось определить причину ответа панели.',
        };

        return $this->httpStatus === null ? $message : "HTTP {$this->httpStatus}: {$message}";
    }
}

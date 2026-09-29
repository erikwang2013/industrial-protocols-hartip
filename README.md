# HART-IP 协议包 — HART over TCP/UDP，端口 5094

> [English](README.en.md)

HART-IP (HART over TCP/UDP)，通过 IP 网络连接 HART-IP 网关，端口 5094。与串口 HART（packages/hart）互补。

## 安装

```bash
composer require erikwang2013/industrial-protocols-hartip
```

## 架构

HartIpDriver（TCP）→ HartIpFrame 帧编解码。9 字节 HART-IP 头 + 序列号管理。

## 功能

HART-IP TCP/UDP 通信、主变量(PV) 读取、回路电流(mA) 读取、序列号自动管理、HartIpException 异常

## 使用说明

```php
$conn = $kernel->getConnectionManager()->connect('hart-ip-gw');
$conn->read('pv');            // 主变量
$conn->read('loop_current');  // 回路电流
```

## 配置示例

```php
'devices' => [
    'hart-ip-gw' => [
        'protocol' => 'hart-ip',
        'host' => '192.168.1.150', 'port' => 5094,
        'timeout' => 5000,
    ],
],
```

## 兼容框架

Laravel / Webman / Hyperf / ThinkPHP / Yii2 / Yii3 / Plain PHP

## 系统要求

- PHP >= 8.1
- HART-IP 网关
- erikwang2013/industrial-protocols-kernel

## License

MIT — Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz

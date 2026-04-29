# binkterm-php MeshCore Bridge

Serial companion bridge between a MeshCore node and the binkterm-php packet BBS gateway API.

The bridge connects to a MeshCore device over USB serial, receives direct messages from radio users, sends each message as a command to the BBS API, and relays the plain-text BBS response back to the originating MeshCore node.

## Features

- Uses the MeshCore binary companion protocol over USB/UART.
- Forwards MeshCore direct messages to `POST /api/packetbbs/command`.
- Polls `GET /api/packetbbs/pending` for queued outbound BBS messages.
- Sends long BBS responses as numbered text chunks.
- Reconnects automatically if the serial port is disconnected.
- Supports manual and scheduled MeshCore adverts.
- Provides optional debug and packet trace logging.
- Runs on PHP 8.1 or newer.

## Requirements

- PHP 8.1 or newer.
- Composer.
- A MeshCore node connected over USB serial.
- A reachable binkterm-php instance with the packet BBS API enabled.
- An API key accepted by the BBS packet API.

Platform notes:

- On Linux and other Unix-like systems, the bridge configures the serial port with `stty`.
- On Windows, PHP's FFI extension must be enabled for non-blocking COM port I/O.

## Installation

Install dependencies:

```sh
composer install
```

Create a local configuration file:

```sh
cp bridge.json.example bridge.json
```

Edit `bridge.json` for your BBS URL, API key, and serial port.

## Configuration

Example:

```json
{
    "bbs_url": "https://yourbbs.example.com",
    "api_key": "replace-with-your-bbs-api-key",
    "serial_port": "/dev/ttyUSB0",
    "baud_rate": 115200,
    "interface": "meshcore",
    "max_send_line_length": 200,
    "inter_chunk_delay_ms": 500,
    "min_command_interval_seconds": 2,
    "post_open_delay_seconds": 0,
    "handshake_timeout_seconds": 30,
    "reconnect_delay_seconds": 5,
    "poll_interval_seconds": 30,
    "advert_zero_hop_interval_seconds": 0,
    "advert_flood_interval_seconds": 0,
    "http_timeout": 15
}
```

Common keys:

| Key | Required | Description |
| --- | --- | --- |
| `bbs_url` | Yes | Base URL for the binkterm-php site, without a trailing API path. |
| `api_key` | Yes | Shared bearer token accepted by the BBS packet API. |
| `serial_port` | Yes | Serial device path, such as `/dev/ttyUSB0`, `/dev/ttyACM0`, or `COM3`. |
| `baud_rate` | No | Serial speed. Defaults to `115200`. |
| `interface` | No | Interface name sent to the BBS API. Defaults to `meshcore`. |
| `max_send_line_length` | No | Maximum response chunk size before splitting long BBS replies. Defaults to `200`. |
| `inter_chunk_delay_ms` | No | Delay between multi-part response chunks. Defaults to `500`. |
| `min_command_interval_seconds` | No | Per-node command rate limit. Defaults to `2`. |
| `post_open_delay_seconds` | No | Delay after opening the serial port before handshaking. Useful for devices that reboot on open. |
| `handshake_timeout_seconds` | No | Time to wait for MeshCore device and self info during startup. Defaults to `30`. |
| `reconnect_delay_seconds` | No | Delay before reopening the serial port after an error. Defaults to `5`. |
| `poll_interval_seconds` | No | How often to poll the BBS for pending outbound messages. Defaults to `30`. |
| `advert_zero_hop_interval_seconds` | No | Automatic local advert interval. `0` disables scheduled zero-hop adverts. |
| `advert_flood_interval_seconds` | No | Automatic flooded advert interval. `0` disables scheduled flood adverts. |
| `http_timeout` | No | HTTP request timeout in seconds. Defaults to `15`. |

Additional optional keys supported by the bridge:

| Key | Description |
| --- | --- |
| `message_probe_interval_seconds` | How often to proactively ask the radio for queued messages. Defaults to `2.0`; set to `0` to disable. |
| `message_sync_timeout_seconds` | How long to wait for a sync response before allowing another sync request. Defaults to `1.0`; set to `0` to disable the timeout. |

## Running

Start with the default `bridge.json`:

```sh
php bridge.php
```

Or through Composer:

```sh
composer start
```

Use a custom config path:

```sh
php bridge.php /path/to/bridge.json
```

Enable bridge-level debug logging:

```sh
php bridge.php --debug
```

Enable decoded packet trace logging:

```sh
php bridge.php --trace
```

Runtime console commands are available only when the bridge can poll STDIN in
non-blocking mode. They are disabled for Windows TTY sessions.

| Command | Description |
| --- | --- |
| `advert` or `a` | Send a zero-hop advert immediately. |
| `flood` or `af` | Send a flooded advert immediately. |
| `help` or `?` | Show available console commands. |
| `quit` or `exit` | Stop the bridge. |

## Message Flow

Startup:

1. Open the serial port.
2. Send MeshCore `DeviceQuery`.
3. Wait for `DeviceInfo`.
4. Send `AppStart`.
5. Wait for `SelfInfo`.
6. Register the bridge node ID with the BBS API client.

Inbound radio-to-BBS flow:

1. MeshCore reports `MsgWaiting`, or the bridge probes for queued messages.
2. The bridge sends `SyncNextMessage` until MeshCore reports `NoMoreMessages`.
3. Each direct contact message is treated as a BBS command.
4. The bridge sends the command to:

   ```text
   POST /api/packetbbs/command
   Authorization: Bearer <api_key>
   ```

5. The plain-text API response is sent back as a MeshCore direct message.

MeshCore requires a contact record for a remote node before messages from that node
will pass through to the bridge. The device can be configured to auto-add users
based on adverts; when another node advertises itself, the local MeshCore node
adds the contact and will then accept messages from that remote user. Contacts can
also be added manually to allow reception from specific remote nodes.

Outbound BBS-to-radio flow:

1. The bridge remembers nodes that have recently contacted it.
2. Every `poll_interval_seconds`, it calls:

   ```text
   GET /api/packetbbs/pending?node_id=<node>&bridge_node_id=<bridge>
   Authorization: Bearer <api_key>
   ```

3. Any returned messages are sent to that MeshCore node.

## Node IDs

The BBS-facing node ID is the lowercase 12-character hex encoding of the 6-byte MeshCore public-key prefix from a received direct message.

Example:

```text
8f12ab34cd56
```

The bridge also sends its own full public key as `bridge_node_id` after MeshCore returns `SelfInfo`.

## Serial Port Examples

Linux:

```json
"serial_port": "/dev/ttyUSB0"
```

Windows:

```json
"serial_port": "COM3"
```

If the serial device cannot be opened on Linux, confirm the user running the bridge has permission to access the port. This often means adding the user to the appropriate serial group, such as `dialout`, then logging out and back in.

## Troubleshooting

`Config file not found`

Copy `bridge.json.example` to `bridge.json` or pass the config path as the first argument.

`Missing required config key`

Set `bbs_url`, `api_key`, and `serial_port` in the config file.

`Cannot open serial device`

Check the serial port name, cable, device power, and OS-level permissions.

`PHP FFI extension is required`

On Windows, enable `ffi` in `php.ini`.

`ERROR: Could not reach BBS`

Check `bbs_url`, `api_key`, TLS certificate validity, network connectivity, and the BBS packet API routes.

No replies on the radio:

- Run with `--debug` to see commands and BBS responses.
- Run with `--trace` to see decoded MeshCore packets.
- Lower `max_send_line_length` if long messages are not transmitting reliably.
- Increase `inter_chunk_delay_ms` if multi-part responses are sent too quickly.

## Project Layout

```text
bridge.php              CLI entry point
bridge.json.example     Example runtime configuration
src/BbsApiClient.php    HTTP client for the packet BBS API
src/MeshCoreBridge.php  Main bridge loop and message handling
src/Packet.php          MeshCore companion packet encoder/decoder
src/SerialPort.php      Cross-platform serial port wrapper
```

## License

BSD-3-Clause. See `LICENSE.md`.

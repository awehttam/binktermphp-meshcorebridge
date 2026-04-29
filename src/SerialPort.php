<?php

namespace MeshCoreBridge;

/**
 * Serial port wrapper with MeshCore companion framing.
 *
 * Frame format (companion protocol over USB/UART):
 *   radio → app  0x3E ('>') + uint16-LE length + payload
 *   app → radio  0x3C ('<') + uint16-LE length + payload
 *
 * The port is opened in non-blocking mode. readFrame() accumulates raw bytes
 * in an internal buffer and returns complete payloads one at a time.
 * Stray bytes before a valid frame-start byte are silently discarded.
 */
class SerialPort
{
    const FRAME_FROM_RADIO = 0x3E; // '>' radio→app
    const FRAME_TO_RADIO   = 0x3C; // '<' app→radio

    private const WIN32_GENERIC_READ           = 0x80000000;
    private const WIN32_GENERIC_WRITE          = 0x40000000;
    private const WIN32_OPEN_EXISTING          = 3;
    private const WIN32_FILE_ATTRIBUTE_NORMAL  = 0x00000080;
    private const WIN32_MAXDWORD               = 0xFFFFFFFF;
    private const WIN32_INVALID_HANDLE_VALUE   = -1;

    private string $device;
    private int $baud;

    /** @var resource|null */
    private $handle = null;

    /** @var \FFI|null */
    private static ?\FFI $kernel32 = null;

    /** @var int|null */
    private ?int $windowsHandle = null;

    private string $buffer = '';

    public function __construct(string $device, int $baud = 115200)
    {
        $this->device = $device;
        $this->baud   = $baud;
    }

    /**
     * Open the serial port and configure it.
     *
     * @throws \RuntimeException on failure
     */
    public function open(): void
    {
        $this->close();
        $this->buffer = '';

        if (PHP_OS_FAMILY === 'Windows') {
            $this->openWindows();
            return;
        }

        $this->openUnix();
        stream_set_blocking($this->handle, false);
    }

    private function openUnix(): void
    {
        if (!file_exists($this->device)) {
            throw new \RuntimeException("Serial device not found: {$this->device}");
        }

        $stty = sprintf(
            'stty -F %s %d cs8 -cstopb -parenb -echo raw -hupcl 2>&1',
            escapeshellarg($this->device),
            $this->baud
        );
        exec($stty, $out, $rc);
        if ($rc !== 0) {
            throw new \RuntimeException("stty failed ({$this->device}): " . implode(' ', $out));
        }

        $h = @fopen($this->device, 'r+b');
        if ($h === false) {
            throw new \RuntimeException("Cannot open serial device: {$this->device}");
        }
        $this->handle = $h;
    }

    private function openWindows(): void
    {
        if (preg_match('/^(COM\d+)$/i', $this->device, $m)) {
            $portName = strtoupper($m[1]);
        } elseif (preg_match('/^\\\\\\\\.\\\\(COM\d+)$/i', $this->device, $m)) {
            $portName = strtoupper($m[1]);
        } else {
            throw new \RuntimeException("Unrecognised Windows COM port: {$this->device}");
        }

        if (!extension_loaded('FFI')) {
            throw new \RuntimeException('PHP FFI extension is required for non-blocking serial I/O on Windows');
        }

        $cmd = sprintf('mode %s: BAUD=%d PARITY=N DATA=8 STOP=1 DTR=on 2>&1', $portName, $this->baud);
        exec($cmd, $out, $rc);
        if ($rc !== 0) {
            throw new \RuntimeException("mode failed ({$portName}): " . implode(' ', $out));
        }

        $api = self::kernel32();
        $winPath = '\\\\.\\' . $portName;
        $handle = $api->CreateFileA(
            $winPath,
            self::WIN32_GENERIC_READ | self::WIN32_GENERIC_WRITE,
            0,
            null,
            self::WIN32_OPEN_EXISTING,
            self::WIN32_FILE_ATTRIBUTE_NORMAL,
            0
        );

        if ($handle === self::WIN32_INVALID_HANDLE_VALUE) {
            throw new \RuntimeException(sprintf(
                'Cannot open serial device: %s (Win32 error %d)',
                $portName,
                $api->GetLastError()
            ));
        }

        $timeouts = $api->new('COMMTIMEOUTS');
        $timeouts->ReadIntervalTimeout         = self::WIN32_MAXDWORD;
        $timeouts->ReadTotalTimeoutMultiplier  = 0;
        $timeouts->ReadTotalTimeoutConstant    = 0;
        $timeouts->WriteTotalTimeoutMultiplier = 0;
        $timeouts->WriteTotalTimeoutConstant   = 0;

        if ($api->SetCommTimeouts($handle, \FFI::addr($timeouts)) === 0) {
            $error = $api->GetLastError();
            $api->CloseHandle($handle);
            throw new \RuntimeException("SetCommTimeouts failed for {$portName} (Win32 error {$error})");
        }

        $this->windowsHandle = $handle;
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }

        if ($this->windowsHandle !== null) {
            self::kernel32()->CloseHandle($this->windowsHandle);
            $this->windowsHandle = null;
        }
    }

    public function isOpen(): bool
    {
        return $this->handle !== null || $this->windowsHandle !== null;
    }

    public function getDevice(): string
    {
        return $this->device;
    }

    /**
     * Non-blocking read.
     *
     * Returns the payload of the next complete frame, or null if no full frame
     * is available yet. Stray bytes before a 0x3E frame-start are discarded.
     */
    public function readFrame(): ?string
    {
        if (!$this->isOpen()) {
            return null;
        }

        $chunk = PHP_OS_FAMILY === 'Windows'
            ? $this->readWindowsChunk()
            : $this->readUnixChunk();

        if ($chunk !== '') {
            $this->buffer .= $chunk;
        }

        while (true) {
            $pos = strpos($this->buffer, chr(self::FRAME_FROM_RADIO));
            if ($pos === false) {
                $this->buffer = '';
                return null;
            }
            if ($pos > 0) {
                $this->buffer = substr($this->buffer, $pos);
            }

            if (strlen($this->buffer) < 3) {
                return null;
            }

            $len = unpack('v', substr($this->buffer, 1, 2))[1];
            if ($len === 0) {
                $this->buffer = substr($this->buffer, 1);
                continue;
            }

            if (strlen($this->buffer) < 3 + $len) {
                return null;
            }

            $payload      = substr($this->buffer, 3, $len);
            $this->buffer = substr($this->buffer, 3 + $len);
            return $payload;
        }
    }

    /**
     * Write a framed command payload to the radio.
     *
     * @throws \RuntimeException on write failure
     */
    public function writeFrame(string $payload): void
    {
        $frame = chr(self::FRAME_TO_RADIO) . pack('v', strlen($payload)) . $payload;
        $this->write($frame);
    }

    private function readUnixChunk(): string
    {
        if ($this->handle === null) {
            return '';
        }

        $read = [$this->handle];
        $write = $except = null;
        $ready = @stream_select($read, $write, $except, 0, 0);

        if ($ready === false || $ready === 0) {
            return '';
        }

        $chunk = fread($this->handle, 4096);
        if ($chunk === false) {
            $this->close();
            throw new \RuntimeException("Serial read error: {$this->device}");
        }
        if ($chunk === '' && feof($this->handle)) {
            $this->close();
            throw new \RuntimeException("Serial port disconnected: {$this->device}");
        }

        return $chunk;
    }

    private function readWindowsChunk(): string
    {
        if ($this->windowsHandle === null) {
            return '';
        }

        $api = self::kernel32();
        $errors = $api->new('DWORD[1]');
        $stat = $api->new('COMSTAT');

        if ($api->ClearCommError($this->windowsHandle, $errors, \FFI::addr($stat)) === 0) {
            $error = $api->GetLastError();
            $this->close();
            throw new \RuntimeException("Serial status error: {$this->device} (Win32 error {$error})");
        }

        $available = (int)$stat->cbInQue;
        if ($available <= 0) {
            return '';
        }

        $toRead = min($available, 4096);
        $buf = $api->new('char[' . $toRead . ']');
        $read = $api->new('DWORD[1]');

        if ($api->ReadFile($this->windowsHandle, $buf, $toRead, $read, null) === 0) {
            $error = $api->GetLastError();
            $this->close();
            throw new \RuntimeException("Serial read error: {$this->device} (Win32 error {$error})");
        }

        $count = (int)$read[0];
        if ($count === 0) {
            return '';
        }

        return \FFI::string($buf, $count);
    }

    private function write(string $data): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->writeWindows($data);
            return;
        }

        if ($this->handle === null) {
            throw new \RuntimeException('Serial port is not open');
        }

        $written = fwrite($this->handle, $data);
        if ($written === false) {
            throw new \RuntimeException('Serial write failed');
        }
        fflush($this->handle);
    }

    private function writeWindows(string $data): void
    {
        if ($this->windowsHandle === null) {
            throw new \RuntimeException('Serial port is not open');
        }

        $api = self::kernel32();
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            $chunk = substr($data, $offset);
            $writeBuffer = $api->new('char[' . strlen($chunk) . ']', false);
            \FFI::memcpy($writeBuffer, $chunk, strlen($chunk));
            $written = $api->new('DWORD[1]');

            if ($api->WriteFile($this->windowsHandle, $writeBuffer, strlen($chunk), $written, null) === 0) {
                $error = $api->GetLastError();
                $this->close();
                throw new \RuntimeException("Serial write failed (Win32 error {$error})");
            }

            $count = (int)$written[0];
            if ($count <= 0) {
                $this->close();
                throw new \RuntimeException('Serial write failed (zero bytes written)');
            }

            $offset += $count;
        }
    }

    private static function kernel32(): \FFI
    {
        if (self::$kernel32 !== null) {
            return self::$kernel32;
        }

        try {
            self::$kernel32 = \FFI::cdef(<<<'CDEF'
typedef long BOOL;
typedef unsigned long DWORD;
typedef intptr_t HANDLE;

typedef struct _COMSTAT {
    DWORD Flags;
    DWORD cbInQue;
    DWORD cbOutQue;
} COMSTAT;

typedef struct _COMMTIMEOUTS {
    DWORD ReadIntervalTimeout;
    DWORD ReadTotalTimeoutMultiplier;
    DWORD ReadTotalTimeoutConstant;
    DWORD WriteTotalTimeoutMultiplier;
    DWORD WriteTotalTimeoutConstant;
} COMMTIMEOUTS;

HANDLE CreateFileA(
    const char *lpFileName,
    DWORD dwDesiredAccess,
    DWORD dwShareMode,
    void *lpSecurityAttributes,
    DWORD dwCreationDisposition,
    DWORD dwFlagsAndAttributes,
    HANDLE hTemplateFile
);
BOOL ReadFile(
    HANDLE hFile,
    void *lpBuffer,
    DWORD nNumberOfBytesToRead,
    DWORD *lpNumberOfBytesRead,
    void *lpOverlapped
);
BOOL WriteFile(
    HANDLE hFile,
    const void *lpBuffer,
    DWORD nNumberOfBytesToWrite,
    DWORD *lpNumberOfBytesWritten,
    void *lpOverlapped
);
BOOL FlushFileBuffers(HANDLE hFile);
BOOL CloseHandle(HANDLE hObject);
BOOL SetCommTimeouts(HANDLE hFile, COMMTIMEOUTS *lpCommTimeouts);
BOOL ClearCommError(HANDLE hFile, DWORD *lpErrors, COMSTAT *lpStat);
DWORD GetLastError(void);
CDEF, 'kernel32.dll');
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to initialise Win32 serial API via FFI: ' . $e->getMessage(), 0, $e);
        }

        return self::$kernel32;
    }
}

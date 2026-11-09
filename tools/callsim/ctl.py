"""Minimal client for baresip's ctrl_tcp module (netstring-framed JSON)."""
import collections
import json
import socket
import time


def _netstring(data: bytes) -> bytes:
    return str(len(data)).encode() + b":" + data + b","


class Phone:
    def __init__(self, host: str, port: int):
        self.sock = socket.create_connection((host, port), timeout=10)
        self.buf = b""
        # Events that arrive while waiting for a command's response are kept here.
        self.events = collections.deque()
        self.seen = []          # every event type received, for diagnostics

    def _read_one(self) -> dict:
        while True:
            if b":" in self.buf:
                head, rest = self.buf.split(b":", 1)
                n = int(head)
                if len(rest) >= n + 1:
                    msg, self.buf = rest[:n], rest[n + 1:]
                    return json.loads(msg)
            chunk = self.sock.recv(65536)
            if not chunk:
                raise ConnectionError("baresip closed the control connection")
            self.buf += chunk

    def command(self, command: str, params: str = "") -> dict:
        """Sends one command and returns its response, skipping events."""
        self.sock.sendall(_netstring(json.dumps(
            {"command": command, "params": params, "token": command}).encode()))
        while True:
            msg = self._read_one()
            if msg.get("response"):
                return msg
            if msg.get("event"):
                self._keep(msg)

    def _keep(self, msg: dict) -> None:
        self.events.append(msg)
        self.seen.append(msg.get("type"))

    def wait_event(self, wanted: str, timeout: float = 30.0) -> dict:
        """Waits for an event such as CALL_INCOMING or CALL_ESTABLISHED.

        Events received earlier are checked first; older events of other
        types are dropped as they are passed over.
        """
        while self.events:
            msg = self.events.popleft()
            if msg.get("type") == wanted:
                return msg
        deadline = time.time() + timeout
        try:
            while True:
                remaining = deadline - time.time()
                if remaining <= 0:
                    raise TimeoutError(f"no {wanted} within {timeout:.0f} s (seen: {self.seen[-8:]})")
                self.sock.settimeout(remaining)
                try:
                    msg = self._read_one()
                except socket.timeout:
                    continue
                if msg.get("event"):
                    self.seen.append(msg.get("type"))
                    if msg.get("type") == wanted:
                        return msg
        finally:
            self.sock.settimeout(10)

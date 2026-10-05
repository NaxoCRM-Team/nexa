# Capture-only webhook receiver on the isolated Docker network.
require "socket"
require "json"
require "timeout"

server = TCPServer.new("0.0.0.0", 8080)
loop do
  client = server.accept
  begin
    Timeout.timeout(10) do
      request = client.gets("\r\n")
      headers = {}
      bytes = 0
      while (line = client.gets("\r\n")) && line != "\r\n"
        bytes += line.bytesize
        raise "Headers too large" if bytes > 8192
        name, value = line.split(":", 2)
        headers[name.downcase] = value.to_s.strip
      end
      length = Integer(headers.fetch("content-length", "0"))
      raise "Invalid payload size" unless length.between?(1, 262144)
      token = File.read("/config/capture-token").strip
      raise "Unauthorized" unless request&.split&.first(2) == ["POST", "/events/#{token}"]
      body = client.read(length)
      payload = JSON.parse(body)
      raise "Invalid event" unless payload.is_a?(Hash)
      File.open("/config/captured-events.jsonl", "a", 0o600) do |file|
        file.flock(File::LOCK_EX)
        file.puts(JSON.generate(payload))
      end
      client.write("HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nOK")
    end
  rescue StandardError
    client.write("HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\nConnection: close\r\n\r\n") rescue nil
  ensure
    client.close
  end
end

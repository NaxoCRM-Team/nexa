# Local capture-only fixture. Never use this bootstrap on a public mail server.
raise "Local capture relay is required" unless ENV.fetch("POSTAL_SMTP_RELAYS", "").include?("mailpit:1025")

user = User.find_or_initialize_by(email_address: "admin@postal.local.test")
if user.new_record?
  user.assign_attributes(first_name: "Nexa", last_name: "Local Postal", admin: true,
                         password: ENV.fetch("POSTAL_LOCAL_ADMIN_PASSWORD"))
  user.save!
end
organization = Organization.find_or_create_by!(permalink: "nexa-local") { |org| org.name = "Nexa local capture"; org.owner = user }
server = organization.servers.find_or_create_by!(permalink: "capture") { |item| item.name = "Local capture"; item.mode = "Live" }
domain = server.domains.find_or_create_by!(name: "nexa.local.test") { |item| item.verification_method = "DNS" }
domain.mark_as_verified unless domain.verified?
credential = server.credentials.find_or_create_by!(name: "Nexa local API", type: "API")
capture_token_path = "/config/capture-token"
File.write(capture_token_path, SecureRandom.hex(24), perm: 0o600) unless File.exist?(capture_token_path)
webhook = server.webhooks.find_or_initialize_by(name: "Nexa local event capture")
webhook.assign_attributes(url: "http://postal-event-capture:8080/events/#{File.read(capture_token_path).strip}", all_events: true, enabled: true)
webhook.save!
File.write("/config/local-credentials.json", JSON.pretty_generate({apiKey: credential.key, server: server.full_permalink,
  apiBaseUrl: "https://localhost:8443", sender: "newsletter@nexa.local.test"}), perm: 0o600)
puts "Local Postal fixture ready; credentials are in the ignored config directory."

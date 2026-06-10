# outputs.tf
output "public_ip" {
  value = aws_instance.quickquiz.public_ip
}

output "public_dns" {
  value = aws_instance.quickquiz.public_dns
}

# outputs.tf
output "public_ip" {
  value = aws_instance.quickquiz.public_ip
}

output "public_dns" {
  value = aws_instance.quickquiz.public_dns
}

output "content_bucket_name" {
  value = aws_s3_bucket.quickquiz_content.id
}

output "instance_profile_name" {
  value = aws_iam_instance_profile.quickquiz.name
}

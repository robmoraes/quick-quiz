# variables.tf
variable "aws_region" {
  default = "us-east-1"
}

variable "ami_id" {
  description = "Amazon Linux 2023 AMI compatible with the selected instance type"
  type        = string
}

variable "instance_type" {
  description = "EC2 instance type used by the single-node beta environment"
  type        = string
  default     = "t3.small"
}

variable "content_bucket_name" {
  description = "Globally unique S3 bucket used for shared QuickQuiz content"
  type        = string
}

variable "key_name" {
  description = "EC2 key pair name"
  type        = string
}

variable "public_key_path" {
  description = "Path to SSH public key"
  type        = string
}

variable "ssh_allowed_cidr" {
  description = "CIDR allowed to access SSH"
  type        = string
}

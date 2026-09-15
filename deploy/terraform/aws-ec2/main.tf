# main.tf
provider "aws" {
  region = var.aws_region
}

resource "aws_key_pair" "quickquiz" {
  key_name   = var.key_name
  public_key = file(var.public_key_path)
}

resource "aws_eip" "quickquiz" {
  domain = "vpc"

  tags = {
    Name        = "quickquiz-beta-eip"
    Project     = "QuickQuiz"
    Owner       = "CarlosRMoraes"
    CostCenter  = "QuickQuiz"
    Environment = "Beta"
    ManagedBy   = "Terraform"
  }
}

resource "aws_instance" "quickquiz" {
  ami           = var.ami_id
  instance_type = var.instance_type

  key_name             = aws_key_pair.quickquiz.key_name
  iam_instance_profile = aws_iam_instance_profile.quickquiz.name

  vpc_security_group_ids = [
    aws_security_group.quickquiz.id
  ]

  user_data = file("${path.module}/user-data.sh")

  tags = {
    Name        = "quickquiz-beta-ec2"
    Project     = "QuickQuiz"
    Environment = "Beta"
    ManagedBy   = "Terraform"
    Owner       = "CarlosRMoraes"
    CostCenter  = "QuickQuiz"
  }
}

resource "aws_s3_bucket" "quickquiz_content" {
  bucket = var.content_bucket_name

  tags = {
    Name        = "quickquiz-beta-content"
    Project     = "QuickQuiz"
    Owner       = "CarlosRMoraes"
    CostCenter  = "QuickQuiz"
    Environment = "Beta"
    ManagedBy   = "Terraform"
  }
}

resource "aws_s3_bucket_public_access_block" "quickquiz_content" {
  bucket = aws_s3_bucket.quickquiz_content.id

  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_server_side_encryption_configuration" "quickquiz_content" {
  bucket = aws_s3_bucket.quickquiz_content.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_s3_bucket_versioning" "quickquiz_content" {
  bucket = aws_s3_bucket.quickquiz_content.id

  versioning_configuration {
    status = "Enabled"
  }
}

resource "aws_iam_role" "quickquiz" {
  name = "quickquiz-beta-ec2"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect = "Allow"
      Principal = {
        Service = "ec2.amazonaws.com"
      }
      Action = "sts:AssumeRole"
    }]
  })

  tags = {
    Project     = "QuickQuiz"
    Owner       = "CarlosRMoraes"
    CostCenter  = "QuickQuiz"
    Environment = "Beta"
    ManagedBy   = "Terraform"
  }
}

resource "aws_iam_role_policy" "quickquiz_content" {
  name = "quickquiz-beta-content-s3"
  role = aws_iam_role.quickquiz.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid      = "ListContentPrefix"
        Effect   = "Allow"
        Action   = "s3:ListBucket"
        Resource = aws_s3_bucket.quickquiz_content.arn
        Condition = {
          StringLike = {
            "s3:prefix" = ["questions", "questions/*"]
          }
        }
      },
      {
        Sid      = "ManageContentObjects"
        Effect   = "Allow"
        Action   = ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"]
        Resource = "${aws_s3_bucket.quickquiz_content.arn}/questions/*"
      }
    ]
  })
}

resource "aws_iam_instance_profile" "quickquiz" {
  name = "quickquiz-beta-ec2"
  role = aws_iam_role.quickquiz.name
}

resource "aws_eip_association" "quickquiz" {
  instance_id   = aws_instance.quickquiz.id
  allocation_id = aws_eip.quickquiz.id
}

resource "aws_security_group" "quickquiz" {
  name        = "quickquiz-beta-sg"
  description = "Security group for QuickQuiz beta"

  ingress {
    description = "HTTP"
    from_port   = 80
    to_port     = 80
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  ingress {
    description = "HTTPS"
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = ["0.0.0.0/0"]
  }

  ingress {
    description = "SSH from current IP"
    from_port   = 22
    to_port     = 22
    protocol    = "tcp"
    cidr_blocks = [var.ssh_allowed_cidr]
  }

  egress {
    description = "Allow all outbound"
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name        = "quickquiz-ec2-sg"
    Project     = "QuickQuiz"
    Environment = "Beta"
    ManagedBy   = "Terraform"
    Owner       = "CarlosRMoraes"
    CostCenter  = "QuickQuiz"
  }
}

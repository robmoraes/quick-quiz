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
  instance_type = "t3.micro"

  key_name = aws_key_pair.quickquiz.key_name

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

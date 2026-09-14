# EC2 SSH Access Recovery

Use this runbook when the permanent SSH key for the QuickQuiz beta EC2 is not
available, but AWS CLI administrative access is working.

## Target

```text
Instance: i-043c1e8324ef79e4c
Availability Zone: us-east-1d
Public IP: 34.207.253.17
OS user: ec2-user
Security group: sg-010d2021f2da6ae61
```

## Prerequisites

- AWS CLI authenticated in account and Region `us-east-1`.
- TCP port 22 allowed from the operator's current public IP.
- A permanent local key pair, such as `~/.ssh/id_ed25519` and
  `~/.ssh/id_ed25519.pub`.
- EC2 Instance Connect installed on the instance.

## Recover access

Create a disposable key:

```bash
ssh-keygen -t ed25519 \
  -f ~/.ssh/id_ed25519_temp \
  -N '' \
  -C 'quickquiz-eic-temporary'
```

Publish the disposable key through EC2 Instance Connect:

```bash
aws ec2-instance-connect send-ssh-public-key \
  --region us-east-1 \
  --availability-zone us-east-1d \
  --instance-id i-043c1e8324ef79e4c \
  --instance-os-user ec2-user \
  --ssh-public-key "$(cat ~/.ssh/id_ed25519_temp.pub)"
```

Within 60 seconds, use the disposable key to install the permanent key:

```bash
ssh-copy-id -f \
  -i ~/.ssh/id_ed25519.pub \
  -o "IdentityFile=$HOME/.ssh/id_ed25519_temp" \
  -o IdentitiesOnly=yes \
  ec2-user@34.207.253.17
```

If the 60-second window expires, publish the disposable key again and repeat
only the `ssh-copy-id` step.

## Validate and clean up

Wait for the disposable authorization to expire, then connect with the
permanent key:

```bash
sleep 65
ssh -o IdentitiesOnly=yes \
  -i ~/.ssh/id_ed25519 \
  ec2-user@34.207.253.17
```

After successful validation, remove the disposable key pair:

```bash
rm ~/.ssh/id_ed25519_temp ~/.ssh/id_ed25519_temp.pub
```

## Troubleshooting

- `Invalid length for parameter SSHPublicKey`: pass the public key contents
  with `"$(cat ~/.ssh/id_ed25519_temp.pub)"`.
- `Permission denied (publickey)`: publish the disposable key again and retry
  within 60 seconds.
- Connection timeout: verify that security group
  `sg-010d2021f2da6ae61` allows TCP 22 from the current public IP.
- Changed host identification: stop and verify the instance and host key before
  changing `known_hosts`.

## Key handling

- Use a different permanent key per workstation.
- Never upload an unencrypted private key to cloud storage.
- Keep the public key wherever operationally convenient.
- If a private-key backup is required, store it in an encrypted password-manager
  vault protected by MFA, or encrypt it locally before uploading it.
- Prefer EC2 Instance Connect or AWS Systems Manager Session Manager as the
  recovery path instead of sharing permanent private keys.

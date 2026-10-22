# Infrastructure ownership

The maintainer-specific AWS definitions were transferred to the private
infrastructure workspace. Terraform state, real variables and credentials
remain outside Git. See [deployment responsibilities](../../docs/deployment.md).

The original local provider cache and any ignored operator files are not part
of this source migration; moving definitions does not migrate Terraform state.

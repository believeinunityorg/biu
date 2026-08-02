async function membershipRequest(
  url: string,
  method: "POST" | "DELETE",
  body?: Record<string, unknown>,
): Promise<{ message?: string; checkout_url?: string | null; requires_payment?: boolean }> {
  const csrf =
    document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") ||
    (window as unknown as { csrf_token?: string }).csrf_token ||
    ""

  const response = await fetch(url, {
    method,
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      "X-CSRF-TOKEN": csrf,
    },
    body: body ? JSON.stringify(body) : undefined,
  })

  const data = await response.json().catch(() => ({}))

  if (!response.ok) {
    throw new Error(data.message || "Could not update membership.")
  }

  return data
}

export function submitMembershipRequest(accountType: string, accountId: number) {
  return membershipRequest(route("membership-requests.store"), "POST", {
    account_type: accountType,
    account_id: accountId,
  })
}

export function acceptMembershipInvitation(invitationId: number) {
  return membershipRequest(route("membership-invitations.accept", invitationId), "POST")
}

export function sendMembershipInvitation(accountType: string, accountId: number, email: string) {
  return membershipRequest(route("membership-invitations.store"), "POST", {
    account_type: accountType,
    account_id: accountId,
    email,
  })
}

export function cancelMembershipInvitation(invitationId: number) {
  return membershipRequest(route("membership-invitations.destroy", invitationId), "DELETE")
}

export function approveMembershipRequest(id: number) {
  return membershipRequest(route("membership-requests.approve", id), "POST")
}

export function declineMembershipRequest(id: number) {
  return membershipRequest(route("membership-requests.decline", id), "POST")
}

export function revokeMembershipMember(id: number) {
  return membershipRequest(route("membership-requests.revoke", id), "POST")
}

export function cancelSupporterMembership(id: number) {
  return membershipRequest(route("membership-requests.destroy", id), "DELETE")
}

export function membershipCheckoutUrl(membershipId: number) {
  return route("membership.payment.checkout", membershipId)
}

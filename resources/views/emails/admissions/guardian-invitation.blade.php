<!doctype html>
<html lang="en">
<body>
    <h1>Guardian authorization request</h1>
    <p>{{ $applicantName }} has requested that you verify your identity and relationship as their {{ $relationship }} for an admissions application at {{ $institutionName }}.</p>
    <p>This link expires in seven days. Sign in to the account matching this email address, verify your email address, and review the request before accepting.</p>
    <p><a href="{{ $invitationUrl }}">Review guardian request</a></p>
    <p>Accepting confirms your identity and your stated relationship. The institution must separately verify the relationship before your application access becomes active. If you did not expect this request, ignore this message.</p>
</body>
</html>

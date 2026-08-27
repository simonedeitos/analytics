namespace ADEtoDB.Models;

public sealed class CadastralParts
{
    public string? Sezione { get; init; }

    public required string Foglio { get; init; }

    public required string Particella { get; init; }
}

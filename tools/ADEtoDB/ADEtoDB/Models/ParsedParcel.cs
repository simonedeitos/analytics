namespace ADEtoDB.Models;

public sealed class ParsedParcel
{
    public string? InspireId { get; init; }

    public string? Label { get; init; }

    public string? NationalReference { get; init; }

    public decimal? AreaMq { get; init; }

    public required List<(double Lat, double Lng)> Points { get; init; }
}

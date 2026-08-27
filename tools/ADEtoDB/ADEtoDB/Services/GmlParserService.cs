using System.Globalization;
using System.Text.RegularExpressions;
using System.Xml.Linq;
using ADEtoDB.Models;

namespace ADEtoDB.Services;

public sealed class GmlParserService
{
    private static readonly Regex NumberRegex = new(@"[-+]?\d*\.?\d+(?:[eE][-+]?\d+)?", RegexOptions.Compiled);

    public IReadOnlyList<ParsedParcel> ParseCadastralParcelsGml(string gmlPath)
    {
        if (!File.Exists(gmlPath)) {
            throw new FileNotFoundException("File GML non leggibile.", gmlPath);
        }

        var document = XDocument.Load(gmlPath, LoadOptions.None);
        var parcels = new List<ParsedParcel>();

        foreach (var parcelElement in document.Descendants().Where(element => NameEquals(element, "CadastralParcel"))) {
            var points = ExtractPolygonPointsFromParcel(parcelElement);
            if (points.Count == 0) {
                continue;
            }

            var inspireIdLocal = FirstDescendantValue(parcelElement, "localId")
                ?? FirstDescendantValue(parcelElement, "INSPIREID_LOCALID")
                ?? FirstDescendantValue(parcelElement, "INSPIREIDLOCALID");

            var inspireIdNamespace = FirstDescendantValue(parcelElement, "namespace")
                ?? FirstDescendantValue(parcelElement, "INSPIREID_NAMESPACE")
                ?? FirstDescendantValue(parcelElement, "INSPIREIDNAMESPACE");

            parcels.Add(new ParsedParcel
            {
                InspireId = ComposeInspireId(inspireIdNamespace, inspireIdLocal),
                Label = FirstDescendantValue(parcelElement, "label") ?? FirstDescendantValue(parcelElement, "LABEL"),
                NationalReference = FirstDescendantValue(parcelElement, "nationalCadastralReference") ?? FirstDescendantValue(parcelElement, "NATIONALCADASTRALREFERENCE"),
                AreaMq = FirstDescendantDecimal(parcelElement, "areaValue") ?? FirstDescendantDecimal(parcelElement, "AREAVALUE"),
                Points = points,
            });
        }

        return parcels;
    }

    private static List<(double Lat, double Lng)> ExtractPolygonPointsFromParcel(XElement parcelElement)
    {
        var polygonElement =
            FirstDescendant(parcelElement, "geometry")?.Descendants().FirstOrDefault(element => NameEquals(element, "Polygon"))
            ?? FirstDescendant(parcelElement, "msGeometry")?.Descendants().FirstOrDefault(element => NameEquals(element, "Polygon"))
            ?? FirstDescendant(parcelElement, "MultiSurface")?.Descendants().FirstOrDefault(element => NameEquals(element, "Polygon"))
            ?? parcelElement.Descendants().FirstOrDefault(element => NameEquals(element, "Polygon"));

        if (polygonElement is null) {
            return [];
        }

        var srsName = ExtractNodeSrsName(polygonElement);
        var posList = polygonElement.Descendants().FirstOrDefault(element => NameEquals(element, "posList"));
        if (posList is not null) {
            var dimension = ReadSrsDimension(posList) ?? ReadSrsDimension(polygonElement) ?? 2;
            return ParseGmlCoordinateSequence(posList.Value, srsName, dimension);
        }

        var posNodes = polygonElement.Descendants().Where(element => NameEquals(element, "pos")).ToList();
        if (posNodes.Count == 0) {
            return [];
        }

        var inferredDimension = ReadSrsDimension(posNodes[0]) ?? ReadSrsDimension(polygonElement) ?? 2;
        return ParseGmlCoordinateSequence(string.Join(' ', posNodes.Select(node => node.Value.Trim())), srsName, inferredDimension);
    }

    private static List<(double Lat, double Lng)> ParseGmlCoordinateSequence(string raw, string? srsName, int dimension)
    {
        var numbers = NumberRegex.Matches(raw)
            .Select(match => double.Parse(match.Value, CultureInfo.InvariantCulture))
            .ToList();

        if (numbers.Count < 4) {
            return [];
        }

        dimension = Math.Max(2, dimension);
        if (numbers.Count < dimension * 2) {
            return [];
        }

        var latFirst = true;
        var first = numbers[0];
        var second = numbers[1];
        if (LooksLikeLngLat(first, second)) {
            latFirst = false;
        } else if (!LooksLikeLatLng(first, second) && SrsPrefersLngLat(srsName)) {
            latFirst = false;
        }

        var points = new List<(double Lat, double Lng)>();
        for (var index = 0; index + (dimension - 1) < numbers.Count; index += dimension) {
            var a = numbers[index];
            var b = numbers[index + 1];
            points.Add(latFirst ? (a, b) : (b, a));
        }

        return NormalizePolygonPoints(points);
    }

    private static string? ComposeInspireId(string? namespaceValue, string? localId)
    {
        if (string.IsNullOrWhiteSpace(localId)) {
            return null;
        }

        var trimmedLocalId = localId.Trim();
        var trimmedNamespace = namespaceValue?.Trim() ?? string.Empty;
        if (trimmedNamespace.Length == 0) {
            return trimmedLocalId;
        }

        return trimmedLocalId.StartsWith(trimmedNamespace, StringComparison.Ordinal) ? trimmedLocalId : trimmedNamespace + trimmedLocalId;
    }

    private static string? ExtractNodeSrsName(XElement node)
    {
        var direct = node.Attributes().FirstOrDefault(attribute => string.Equals(attribute.Name.LocalName, "srsName", StringComparison.OrdinalIgnoreCase))?.Value;
        if (!string.IsNullOrWhiteSpace(direct)) {
            return direct.Trim();
        }

        return node.Descendants()
            .SelectMany(element => element.Attributes())
            .FirstOrDefault(attribute => string.Equals(attribute.Name.LocalName, "srsName", StringComparison.OrdinalIgnoreCase))
            ?.Value
            ?.Trim();
    }

    private static int? ReadSrsDimension(XElement node)
    {
        var raw = node.Attributes().FirstOrDefault(attribute => string.Equals(attribute.Name.LocalName, "srsDimension", StringComparison.OrdinalIgnoreCase))?.Value;
        if (int.TryParse(raw, NumberStyles.Integer, CultureInfo.InvariantCulture, out var dimension) && dimension >= 2) {
            return dimension;
        }

        return null;
    }

    private static XElement? FirstDescendant(XElement context, string localName)
    {
        return context.Descendants().FirstOrDefault(element => NameEquals(element, localName));
    }

    private static string? FirstDescendantValue(XElement context, string localName)
    {
        return context.Descendants()
            .FirstOrDefault(element => NameEquals(element, localName))
            ?.Value
            ?.Trim();
    }

    private static decimal? FirstDescendantDecimal(XElement context, string localName)
    {
        var raw = FirstDescendantValue(context, localName);
        return decimal.TryParse(raw, NumberStyles.Float, CultureInfo.InvariantCulture, out var value) ? value : null;
    }

    private static bool NameEquals(XElement element, string localName)
    {
        return string.Equals(element.Name.LocalName, localName, StringComparison.OrdinalIgnoreCase);
    }

    private static bool SrsPrefersLngLat(string? srsName)
    {
        if (string.IsNullOrWhiteSpace(srsName)) {
            return false;
        }

        // EPSG:4326 / EPSG:4258 sono intenzionalmente omessi: in GML sono lat-first,
        // quindi il default "non preferire lng/lat" è il comportamento corretto.
        var normalized = srsName.ToUpperInvariant();
        return normalized.Contains("EPSG::3857", StringComparison.Ordinal)
            || normalized.Contains("EPSG/0/3857", StringComparison.Ordinal)
            || normalized.Contains("EPSG:3857", StringComparison.Ordinal)
            || normalized.Contains("EPSG::3003", StringComparison.Ordinal)
            || normalized.Contains("EPSG/0/3003", StringComparison.Ordinal)
            || normalized.Contains("EPSG:3003", StringComparison.Ordinal)
            || normalized.Contains("EPSG::3004", StringComparison.Ordinal)
            || normalized.Contains("EPSG/0/3004", StringComparison.Ordinal)
            || normalized.Contains("EPSG:3004", StringComparison.Ordinal)
            || normalized.Contains("EPSG::32632", StringComparison.Ordinal)
            || normalized.Contains("EPSG/0/32632", StringComparison.Ordinal)
            || normalized.Contains("EPSG:32632", StringComparison.Ordinal)
            || normalized.Contains("EPSG::32633", StringComparison.Ordinal)
            || normalized.Contains("EPSG/0/32633", StringComparison.Ordinal)
            || normalized.Contains("EPSG:32633", StringComparison.Ordinal);
    }

    private static bool LooksLikeLatLng(double first, double second)
    {
        return first is >= 35.0 and <= 48.5 && second is >= 6.0 and <= 19.5;
    }

    private static bool LooksLikeLngLat(double first, double second)
    {
        return first is >= 6.0 and <= 19.5 && second is >= 35.0 and <= 48.5;
    }

    private static List<(double Lat, double Lng)> NormalizePolygonPoints(IEnumerable<(double Lat, double Lng)> points)
    {
        var normalized = new List<(double Lat, double Lng)>();
        foreach (var point in points) {
            if (!double.IsFinite(point.Lat) || !double.IsFinite(point.Lng)) {
                continue;
            }

            if (normalized.Count > 0 && Math.Abs(normalized[^1].Lat - point.Lat) < 1e-12 && Math.Abs(normalized[^1].Lng - point.Lng) < 1e-12) {
                continue;
            }

            normalized.Add(point);
        }

        if (normalized.Count >= 2) {
            var first = normalized[0];
            var last = normalized[^1];
            if (Math.Abs(first.Lat - last.Lat) > 1e-12 || Math.Abs(first.Lng - last.Lng) > 1e-12) {
                normalized.Add(first);
            }
        }

        return normalized;
    }
}
